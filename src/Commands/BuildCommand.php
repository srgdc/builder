<?php
namespace wapmorgan\Builder\Commands;

use \Exception;

use wapmorgan\Builder\ConfigurationReader;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Logger\ConsoleLogger;

class BuildCommand extends Command {
    protected function configure() {
        $this
            ->setName('build')
            ->setDescription('Bootstrap and set up a script')
            ->addArgument('name', InputArgument::REQUIRED, 'What script do you want install?')
            ->addArgument('version', InputArgument::OPTIONAL, 'What version of script do you want install?')
            ->addOption('configuration', 'c', InputOption::VALUE_REQUIRED, 'You can pass name of file that contains information about script (supports different file formats)');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $script = $input->getArgument('name');
        $logger = new ConsoleLogger($output);
        $output->writeln('<info>Looking for available versions for '.$script.'</info>');

        $version = $input->getArgument('version');

        $configuration = $input->getOption('configuration');
        if (!empty($configuration)) {
            if (!file_exists($configuration)) {
                $output->writeln('<error>Error! File '.$configuration.' is not found!</error>');
                return false;
            }
            $configuration = realpath($configuration);
            $output->writeln('<info>Retrieving information from config file</info>');
            $reader = new ConfigurationReader($configuration);
            $repository = $reader->getRepository();
            if (isset($repository['version']))
                $version = $repository['version'];
        } else {
            if (empty($version)) {
                $data = $this->loadInformation($script);
                if (empty($data['versions'])) {
                    $output->writeln('<error>Sorry, but there is no information about versions for script "'.$script.'" in my database</error>');
                    return false;
                } else {
                    $output->writeln('<info>You have not specified information for script "'.$script.'", choose version from following list</info>');
                    $helper = $this->getHelper('question');
                    $question = new ChoiceQuestion(
                        'Please select version of script you want install (defaults to latest - '.$data['versions'][0].')',
                        $data['versions'],
                        0
                    );
                    $question->setErrorMessage('There is no version %s');
                    $version = $helper->ask($input, $output, $question);
                }
            }
        }

        if (!isset($data))
            $data = $this->loadInformation($script);

        $installer = '\\wapmorgan\\Builder\\Installers\\'.(isset($data['installer']) ? $data['installer'] : ucfirst($script).'Installer');
        if (!class_exists($installer)) {
            $output->writeln('<error>Installer for script is not available!</error>');
            return false;
        }

        foreach ($data['steps'] as $i => $step) {
            $output->writeln('<info>'.$step['message'].'</info>');
            switch ($step['action']) {
                case 'download':
                    $archive_tmp = tempnam(sys_get_temp_dir(), 'builder');
                    $result = call_user_func(array($installer, 'download'), $data['download_url'], $version, $archive_tmp, $logger);
                    if (!$result) {
                        $output->writeln('<error>Unexpected error occured during this step</error>');
                        return false;
                    }
                    break;

                case 'checkhash':
                    if (!isset($archive_tmp))
                        throw new Exception('Inconsistency in steps! There should be `download` action before `checkhash` action!');
                    $result = call_user_func(array($installer, 'checkhash'), $data['hashsum_url'], $version, $archive_tmp, $logger);
                    if (!$result) {
                        $output->writeln('<error>Unexpected error occured during this step</error>');
                        return false;
                    }
                    break;

                case 'extract':
                    $result = call_user_func(array($installer, 'extract'), $archive_tmp, getcwd(), isset($step['prefix_folder']) ? str_replace('{%version}', $version, $step['prefix_folder']) : null, $logger);
                    if (!$result) {
                        $output->writeln('<error>Unexpected error occured during this step</error>');
                        return false;
                    }
                    break;

                case 'user':
                    $result = call_user_func(array($installer, $step['trigger']), isset($repository) ? array_merge($data, $repository->getArray()) : $data, getcwd(), $logger);
                    break;
            }
        }

        // clean up
        if (isset($archive_tmp))
            call_user_func(array($installer, 'clean'), $archive_tmp, $logger);

        $output->writeln('<info>Successfully installed '.$script.' '.$version.'</info>');
        return true;
    }

    protected function loadInformation($name) {
        $file = __DIR__.'/../../repository/'.$name.'.json';
        if (!file_exists($file))
            throw new Exception('Information for script "'.$name.'" not found in repository');
        $data = json_decode(file_get_contents($file), true);
        if ($data === null)
            throw new Exception('Information for script "'.$name.'" is invalid: json error #'.json_last_error());
        if ($data['name'] != $name)
            throw new Exception('Inconsistency in file "'.$file.'" for script "'.$name.'"');
        return $data;
    }
}
