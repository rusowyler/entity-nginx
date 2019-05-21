<?php namespace Entity\NginxCli\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

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
        $io = new SymfonyStyle($input, $output);

        $io->title("BASIC NGINX ADMINS HELPER");

        $io->section('Data validation');

        // I'm root?
        if (trim(shell_exec('whoami')) != 'root') {
            $io->error('You need to be root to perform these actions. Prefix with sudo');
            die;
        }

        $fileSystem = new Filesystem();

        $domain   = $input->getArgument('domain');
        $user     = $input->getOption('user');
        $template = $input->getOption('template');
        $folder   = empty($input->getOption('folder')) ? $domain : $input->getOption('folder');

        $nginxTemplate      = self::NGINX_PATH . self::NGINX_TEMPLATES . '/' . $template;
        $nginxServer        = self::NGINX_PATH . self::NGINX_AVAILABLE . '/' . $domain;
        $nginxServerEnabled = self::NGINX_PATH . self::NGINX_ENABLED . '/' . $domain;

        if (empty($domain)) {
            $io->error('Domain can\'t be empty');
            die;
        }

        if (empty($user)) {
            $io->error('User option can\'t be empty');
            die;
        }

        if (empty($template) || !in_array($template, ['magento-1', 'wordpress', 'envoyer'])) {
            $io->error('Template option must be valid value');
            die;
        }

        if ( !$fileSystem->exists(self::NGINX_PATH)) {
            $io->error('Nginx folder not found');
            die;
        }
        else {
            $io->success("NGINX found");
        }

        if ( !$fileSystem->exists($nginxTemplate)) {
            $io->error('Nginx templates file not found in {$nginxTemplate}');
            die;
        } else {
            $io->success("NGINX template found");
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


        // Chequeo que no exista ni el nginx server ni la carpeta de destino
        if ($fileSystem->exists($rootPath)) {
            $io->error("Folder already exists ({$rootPath})");
            die;
        } else {
            $io->success("Data folder doesn't exist");
        }

        if ($fileSystem->exists($nginxServer)) {
            $io->error("Server already exists ({$nginxServer})");
            die;
        } else {
            $io->success("Server doesn't exist");
        }

        $io->listing([
                         "Domain: {$domain}",
                         "Template: {$template}",
                         "Folder: {$folder} ({$rootPath})",
                         "Document Root: {$sitePath})",
                         "User: {$user}",
                     ]
        );

        $io->section('Making site container');

        // Creo las carpetas:
        $fileSystem->mkdir($rootPath . $sitePath);
        $fileSystem->mkdir($rootPath . '/logs');
        $fileSystem->mkdir($rootPath . '/cache');
        $fileSystem->chown($rootPath, $user, true);

        $io->section('Creating and tuning NGINX server files');

        // Copio y personalizo el template
        $templateContent = file_get_contents($nginxTemplate);
        if ( !$templateContent) {
            $io->error("Could not read template ({$nginxTemplate})");
            die;
        }

        $templateContent = str_replace('{path}', $rootPath, $templateContent);
        $templateContent = str_replace('{domain}', $domain, $templateContent);
        $templateContent = str_replace('{root}', $sitePath, $templateContent);

        if ( !file_put_contents($nginxServer, $templateContent)) {
            $io->error("Could not create new server file ({$nginxServer})");
            die;
        }

        $io->section('Enabling and reloading NGINX');

        // Habilito el server!
        $fileSystem->symlink($nginxServer, $nginxServerEnabled);
        $io->success("Site ({$domain} enabled.)");


        // Prueba la configuración
        exec('nginx -t 2>&1', $results, $return);
        if ($return != 0) {
            $io->error("NGINX Syntax error. Disabling site (removing symlink).");
            $fileSystem->remove($nginxServerEnabled);
            die;
        } else {
            $io->success("NGINX syntax check OK");
        }

        // Hago un reload
        $output->writeln('<comment>Nginx reload</comment>');
        exec('service nginx reload 2>&1', $results, $return);

        if ($return != 0) {
            $io->error("NGINX reload failed!!!!!");
        } else {
            $io->success("NGINX and {$domain} are ready to go...");
        }

        die;
    }
}