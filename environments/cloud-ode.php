<?php

/**
 * Proof of concept of GitHub, Pipelines, and Cloud ODE integration.
 * Be kind, this is a quick hack.
 */

require 'vendor/autoload.php';
require 'cloudapi.php';

use Acquia\Hmac\Guzzle\HmacAuthMiddleware;
use Acquia\Hmac\Key;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

class CloudODE {
    function __construct($api, $opts = []) {
        $this->api = $api;
        $this->app = getenv('PIPELINE_APPLICATION_ID');
        $this->deploy_path = getenv('PIPELINE_DEPLOY_VCS_PATH');
        $this->event = getenv('PIPELINES_EVENT');
        if (!isset($this->event)) {
            $this->event = 'build';
        }
    }

    // Delete all ODEs deploying the current deploy_path.
    function delete() {
        $envs = $this->api->get("applications/{$this->app}/environments");
        foreach ($envs->_embedded->items as $env) {
            if ($env->flags->ode == 1 && $env->vcs->path == $this->deploy_path) {
                print "Deleting environment {$env->label} ({$env->name}).\n";
                $this->api->delete("environments/{$env->id}");
            }
        }
    }

    // Find the first ODE for which a callback returns true.
    function find_ode($envs, $callback) {
        foreach ($envs as $env) {
            if ($env->flags->ode == 1 && $callback($env)) {
                return $env;
            }
        }
        return NULL;
    }

    // Create or update an ODE for the current build.  Environments are tied
    // to builds by the environment label being the build branch name, since
    // that is the only way we have to identify them.  Method:
    //
    // - If an ODE for the build does not exist, create it, configure it to
    // deploy the build branch, and wait for it to be done.
    // - If an ODE for the build does exist, update with the latest build.
    //
    // @todo: We currently have no way to determine when a git push is deployed.
    function deploy() {
        $label = $this->deploy_path;
        try {
            // Find the build environment, if it exists. The label is the only
            // way we have to identify it.
            $envs = $this->api->get("applications/{$this->app}/environments");
            $env = $this->find_ode($envs->_embedded->items, function ($env) use ($label) {
                return $env->label == $label;
            });

            if ($env) {
                // Deploy the new build.
                // @todo: No way to know when it is done.
                print "Updating Cloud environment {$env->label} ({$env->name}).\n";
            }
            else {
                // Create the environment. We cannot select a branch that does
                // not exist yet.
                print "Creating Cloud environment...\n";
                $this->api->post("applications/{$this->app}/environments", [
                    'label' => $label,
                    'branch' => 'master',
                ]);

                // Find the environment we just created, again via label.
                // @todo: Could the POST call return the env id?
                $envs = $this->api->get("applications/{$this->app}/environments");
                $env = $this->find_ode($envs->_embedded->items, function ($env) use ($label) {
                    return $env->label == $label;
                });

                // Wait for environment to be ready.
                print "Waiting for environment {$env->label} ({$env->name}) to be ready...\n";
                $this->api->poll("environments/{$env->id}", function ($env, $count) {
                    print "tick $count: {$env->status}\n";
                    return $env->status == 'normal';
                });

                // Select the build branch, even if it doesn't exist yet.
                $this->api->post("environments/{$env->id}/code/actions/switch", [
                    'branch' => $this->deploy_path
                ]);

                // @todo: Wait until the branch is actually deployed.
                // Currently not sure how to do that.
            }
        }
        catch (CloudAPI\Exception $e) {
            print "Cloud API error: " . $e->getMessage();
            exit(1);
        }
    }

    function execute() {
        switch ($this->event) {
        case 'build':
            $this->deploy();
            break;

        case 'merge':
            $this->delete();
            break;
        }
    }
}

$key = getenv('N3_KEY');
$secret = getenv('N3_SECRET');
if (empty($key) || empty($secret)) {
    print "N3_KEY and N3_SECRET environment variables are required.\n";
    exit(1);
}
$api = new CloudAPI\QuickCloudAPI($key, $secret, [
    'debug' => getenv('ENVIRONMENTS_DEBUG'),
]);
$ode = new CloudODE($api);
$ode->execute();
