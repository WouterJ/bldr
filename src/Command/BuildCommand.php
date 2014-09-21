<?php

/**
 * This file is part of Bldr.io
 *
 * (c) Aaron Scherer <aequasi@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE
 */

namespace Bldr\Command;

use Bldr\Application;
use Bldr\Event;
use Bldr\Event as Events;
use Bldr\Model\Task;
use Bldr\Registry\TaskRegistry;
use Bldr\Service\Builder;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Aaron Scherer <aequasi@gmail.com>
 */
class BuildCommand extends AbstractCommand
{
    /**
     * @var Builder $builder
     */
    private $builder;

    /**
     * @var TaskRegistry $tasks
     */
    private $tasks;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this->setName('build')
            ->setDescription("Builds the project for the directory you are in. Must contain a config file.")
            ->addArgument('profile', InputArgument::REQUIRED, 'Profile to run')
            ->addOption('tasks', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tasks to run')
            ->setHelp(
                <<<EOF

The <info>%command.name%</info> builds the current project, using the config file in the root directory.

To use:

    <info>$ bldr %command.name%</info>

To specify a profile:

    <info>$ bldr %command.name% -p profile_name</info>

To specify tasks to run:

    <info>$ bldr %command.name% --tasks=task_name</info>
    <info>$ bldr %command.name% --tasks=task_name -t second_task</info>
    <info>$ bldr %command.name% --tasks=task_name,second_task</info>

EOF
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setInput($input)
            ->setOutput($output)
            ->getApplication()
            ->setBuildName();

        $this->tasks = $this->container->get('bldr.registry.task');
        $this->builder = $this->container->get('bldr.builder');
        $this->builder->initialize($input, $output, $this->getHelperSet());

        $this->output->writeln(["\n", Application::$logo, "\n"]);

        $this->doExecute($this->input->getArgument('profile'), $this->input->getOption('tasks'));

        $this->succeedBuild();

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    private function doExecute($profileName = null, array $tasks = [])
    {
        if ([] === $tasks) {
            $profile = $this->getProfile($profileName);

            $projectFormat = [];

            if ($this->container->getParameter('name') !== '') {
                $projectFormat[] = sprintf("Building the '%s' project", $this->container->getParameter('name'));
            }
            if ($this->container->getParameter('description') !== '') {
                $projectFormat[] = sprintf(" - %s - ", $this->container->getParameter('description'));
            }

            $profileFormat = [
                sprintf("Using the '%s' profile", $profileName)
            ];
            if (!empty($profile['description'])) {
                $profileFormat[] = sprintf(" - %s - ", $profile['description']);
            }

            $this->output->writeln(
                [
                    "",
                    $projectFormat === [] ? '' : $this->formatBlock($projectFormat, 'blue', 'black'),
                    "",
                    $this->formatBlock($profileFormat, 'blue', 'black'),
                    ""
                ]
            );

            $this->fetchTasks($profile);
            $this->addEvent(Event::PRE_PROFILE, new Events\ProfileEvent($this, true));
        } else {
            $this->buildTasks($tasks);
        }

        $this->container->get('bldr.builder')->runTasks($this->tasks);

        if ([] === $tasks) {
            $this->addEvent(Event::POST_PROFILE, new Events\ProfileEvent($this, false));
        }
    }

    /**
     * @param string|array $output
     * @param string       $background
     * @param string       $foreground
     *
     * @return string
     */
    private function formatBlock($output, $background, $foreground)
    {
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        return $formatter->formatBlock($output, "bg={$background};fg={$foreground}");
    }

    /**
     * @param array $profile
     */
    private function fetchTasks(array $profile)
    {
        if (!empty($profile['uses']) && !empty($profile['uses']['before'])) {
            foreach ($profile['uses']['before'] as $name) {
                $this->buildTasks($this->getProfile($name)['tasks']);
            }
        }

        $this->buildTasks($profile['tasks']);

        if (!empty($profile['uses']) && !empty($profile['uses']['after'])) {
            foreach ($profile['uses']['after'] as $name) {
                $this->buildTasks($this->getProfile($name)['tasks']);
            }
        }
    }

    /**
     * @param string[] $names
     *
     * @throws \Exception
     * @return array
     */
    private function buildTasks($names)
    {
        $tasks = $this->container->getParameter('tasks');
        foreach ($names as $name) {
            if (!array_key_exists($name, $tasks)) {
                throw new \Exception(
                    sprintf(
                        "Task `%s` does not exist. Found: %s",
                        $name,
                        implode(', ', array_keys($tasks))
                    )
                );
            }
            $taskInfo     = $tasks[$name];
            $description  = isset($taskInfo['description']) ? $taskInfo['description'] : "";
            $runOnFailure = isset($taskInfo['runOnFailure']) ? $taskInfo['runOnFailure'] : false;
            $task         = new Task($name, $description, $runOnFailure, $taskInfo['calls']);
            $this->tasks->addTask($task);
        }
    }

    /**
     * @param $name
     *
     * @return mixed
     * @throws \Exception
     */
    private function getProfile($name)
    {
        $profiles = $this->container->getParameter('profiles');
        if (!array_key_exists($name, $profiles)) {
            throw new \Exception(
                sprintf(
                    'There is no profile with the name \'%s\', expecting: (%s)',
                    $name,
                    implode(', ', array_keys($profiles))
                )
            );
        }

        return $profiles[$name];
    }

    /**
     * @return int
     */
    private function succeedBuild()
    {
        $this->output->writeln(['', $this->formatBlock('Build Success!', 'green', 'white'), '']);

        return 0;
    }
}
