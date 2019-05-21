<?php namespace Entity\NginxCli\Console;

use function Couchbase\defaultDecoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class CreateSiteCommand extends Command
{
    const ROOT_PATH = '/var/www/';
    const NGINX_PATH = '/etc/nginx/';
    const NGINX_AVAILABLE = 'sites-available';
    const NGINX_ENABLED = 'sites-enabled';
    const NGINX_TEMPLATES = 'templates';

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('Creates a new site')
            ->addArgument(
                'domain',
                InputArgument::REQUIRED,
                'Domain of the site to create'
            )
            ->addOption(
                'folder',
                null,
                InputOption::VALUE_OPTIONAL,
                'Custom files folder name'
            )
            ->addOption(
                'template',
                null,
                InputOption::VALUE_REQUIRED,
                'Which template do you want to use (magento-1, wordpress, envoyer)?'
            )
            ->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'User to chown'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // I'm root?
        if(trim(shell_exec('whoami')) != 'root'){
            $output->writeln("<error>You need to be root to perform these actions. Prefix with sudo</error>");
            die;
        }

        $fileSystem = new Filesystem();

        $domain   = $input->getArgument('domain');
        $user     = $input->getOption('user');
        $template = $input->getOption('template');
        $folder   = empty($input->getOption('folder')) ? $domain : $input->getOption('folder');

        $nginxTemplate = self::NGINX_PATH . self::NGINX_TEMPLATES . '/' . $template;

        if (empty($domain)) {
            $output->writeln("<error>Domain can't be empty</error>");
            die;
        }

        if (empty($user)) {
            $output->writeln("<error>User option can't be empty</error>");
            die;
        }

        if (empty($template) || !in_array($template, ['magento-1', 'wordpress', 'envoyer'])) {
            $output->writeln("<error>Template option must be valid value</error>");
            die;
        }

        if ( !$fileSystem->exists(self::NGINX_PATH)) {
            $output->writeln("<error>Nginx folder not found</error>");
            die;
        }

        if ( !$fileSystem->exists($nginxTemplate)) {
            $output->writeln("<error>Nginx templates file not found in {$nginxTemplate}</error>");
            die;
        }

        $rootPath = self::ROOT_PATH . $folder;

        switch ($template) {
            case 'wordpress':
                $sitePath = '/public';
                break;
            case 'magento-1':
                $sitePath = '/public/htdocs';
                break;
            case 'envoyer':
                $sitePath = '/current/public';
                break;
        }

        $output->writeln("<info>Domain: {$domain}</info>");
        $output->writeln("<info>Template: {$template}</info>");
        $output->writeln("<info>Folder: {$folder} ({$rootPath})</info>");
        $output->writeln("<info>Document Root: {$sitePath})</info>");
        $output->writeln("<info>User: {$user}</info>");


        // Chequeo que no exista ni el nginx server ni la carpeta de destino
        if ($fileSystem->exists($rootPath)) {
            $output->writeln("<error>Folder already exists</error>");
            die;
        }

        if ($fileSystem->exists(self::NGINX_PATH . self::NGINX_AVAILABLE . '/' . $domain)) {
            $output->writeln("<error>Server already exists</error>");
            die;
        }

        // Creo las carpetas:
        $fileSystem->mkdir($rootPath . $sitePath);
        $fileSystem->mkdir($rootPath . '/logs');
        $fileSystem->mkdir($rootPath . '/cache');
        $fileSystem->chown($rootPath, $user, true);

        // Copio y personalizo el template
        $nginxServer        = self::NGINX_PATH . self::NGINX_AVAILABLE . '/' . $domain;
        $nginxServerEnabled = self::NGINX_PATH . self::NGINX_ENABLED . '/' . $domain;

        $templateContent = file_get_contents($nginxTemplate);
        if ( !$templateContent) {
            $output->writeln("<error>Could not read template</error>");
            die;
        }

        $templateContent = str_replace('{path}', $rootPath, $templateContent);
        $templateContent = str_replace('{domain}', $domain, $templateContent);
        $templateContent = str_replace('{root}', $sitePath, $templateContent);

        if ( !file_put_contents($nginxServer, $templateContent)) {
            $output->writeln("<error>Could not create new server file</error>");
            die;
        }

        // Habilito el server!
        $output->writeln('<comment>Enabling site</comment>');
        $fileSystem->symlink($nginxServer, $nginxServerEnabled);

        // Prueba la configuración
        $output->writeln('<comment>Nginx check config</comment>');
        exec('nginx -t 2>&1', $results, $return);

        if ($return != 0) {
            $output->writeln("<error>Nginx Syntax error. Disabling site (removing symlink).</error>");
            $fileSystem->remove($nginxServerEnabled);
            die;
        }

        // Hago un reload
        $output->writeln('<comment>Nginx reload</comment>');
        exec('service nginx reload 2>&1', $results, $return);

        if ($return != 0) {
            $output->writeln("<error>Nginx reload failed!!!!!</error>");
        }

        die;
    }
}