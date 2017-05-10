<?php
/**
 * Copyright 2017 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Runtimes\Builder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GenFilesCommand extends Command
{
    const APP_DIR = '/app';
    const DEFAULT_BASE_IMAGE = 'gcr.io/google-appengine/php-base:latest';
    const DEFAULT_WORKSPACE = '/workspace';
    const DEFAULT_YAML_PATH = 'app.yaml';

    /* @var string */
    private $workspace;

    /* @var array */
    private $appYaml;

    /* @var \Twig_Environment */
    private $twig;

    /**
     * Constructor allows injecting the workspace directory.
     */
    public function __construct($workspace = null)
    {
        parent::__construct();
        if ($workspace === null) {
            $workspace = getenv('PWD')
                ?: self::DEFAULT_WORKSPACE;
        }
        $this->workspace = $workspace;
        $yamlPath = getenv('GAE_APPLICATION_YAML_PATH')
            ?: self::DEFAULT_YAML_PATH;
        if (file_exists($this->workspace . '/' . $yamlPath)) {
            $this->appYaml = Yaml::parse(
                file_get_contents($this->workspace . '/' . $yamlPath));
        }
        $loader = new \Twig_Loader_Filesystem(__DIR__ . '/templates');
        $this->twig = new \Twig_Environment($loader);
    }

    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('Create Dockerfile and .dockerignore file')
            ->addArgument(
                'base-image',
                InputArgument::OPTIONAL,
                'The base image of the Dockerfile'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$baseImage = $input->getArgument('base-image')) {
            $baseImage = self::DEFAULT_BASE_IMAGE;
        }
        $this->createDockerfile($baseImage);
        $this->createDockerignore();
    }

    protected function envsFromAppYaml()
    {
        return array_key_exists('env_variables', $this->appYaml)
            ? $this->appYaml['env_variables']
            : [];
    }


    protected function envsFromRuntimeConfig()
    {
        $ret = [];
        if (!array_key_exists('runtime_config', $this->appYaml)) {
            return $ret;
        }
        $runtime_config = $this->appYaml['runtime_config'];

        // TODO: Generalize this process
        if (array_key_exists('whitelist_functions', $runtime_config)) {
            $ret['WHITELIST_FUNCTIONS'] =
                $runtime_config['whitelist_functions'];
        }
        if (array_key_exists('document_root', $runtime_config)) {
            $ret['DOCUMENT_ROOT'] =
                self::APP_DIR . '/' . $runtime_config['document_root'];
        }
        return $ret;
    }

    /**
     * Creates a Dockerfile if it doesn't exist in the workspace.
     */
    public function createDockerfile($baseImage = '')
    {
        if (file_exists($this->workspace . '/Dockerfile')) {
            echo 'not creating Dockerfile because the file already exists'
                . PHP_EOL;
            return;
        }
        if (empty($baseImage)) {
            $baseImage = self::DEFAULT_BASE_IMAGE;
        }

        $envs = $this->envsFromRuntimeConfig()
            + $this->envsFromAppYaml()
            + [
                'DOCUMENT_ROOT' => self::APP_DIR,
                'GOOGLE_RUNTIME_RUN_COMPOSER_SCRIPT' => 'true'
            ];
        $envString = 'ENV ';
        foreach ($envs as $key => $value) {
            $envString .= "$key=$value \\\n";
        }
        // Remove the last new line and the backslash
        $envString = rtrim($envString, "\n");
        $envString = rtrim($envString, '\\');
        $template = $this->twig->load('Dockerfile.twig');
        $dockerfile = $template->render(array(
            'base_image' => $baseImage,
            'env_string' => $envString
        ));
        file_put_contents($this->workspace . '/Dockerfile', $dockerfile);
    }

    /**
     * Creates .dockerignore, or adds some lines to an existing one.
     */
    public function createDockerignore()
    {
        $template = $this->twig->load('dockerignore.twig');
        $yamlPath = getenv('GAE_APPLICATION_YAML_PATH')
            ?: '';
        $dockerignore = "\n"
            . $template->render(['app_yaml_path' => $yamlPath]);
        $fp = fopen($this->workspace . '/.dockerignore', 'a');
        fwrite($fp, $dockerignore);
        fclose($fp);
    }
}