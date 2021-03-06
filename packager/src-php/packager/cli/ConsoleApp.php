<?php

namespace packager\cli;

use DefaultPlugin;
use packager\Annotations;
use packager\Event;
use packager\JavaExec;
use packager\Package;
use packager\Packager;
use packager\Repository;
use packager\server\Server;
use packager\Vendor;
use php\io\File;
use php\io\IOException;
use php\io\Stream;
use php\lang\Invoker;
use php\lang\System;
use php\lang\Thread;
use php\lib\arr;
use php\lib\fs;
use php\lib\str;
use php\time\Timer;
use Tasks;
use text\TextWord;

/**
 * Class ConsoleApp
 * @package packager\cli
 */
class ConsoleApp
{
    private $debug = false;
    private $flags = [];

    /**
     * @var callable[]
     */
    private $commands = [];

    /**
     * @var Packager
     */
    private $packager;

    private $taskUpDate = [];

    function main(array $args)
    {
        try {
            $this->packager = new Packager();

            $args = flow($args)->find(function ($arg) {
                if (str::startsWith($arg, "--")) {
                    $this->flags[str::sub($arg, 2)] = true;
                    return false;
                }

                if (str::startsWith($arg, "-")) {
                    $this->flags[str::sub($arg, 1)] = true;
                    return false;
                }

                return true;
            })->toArray();

            $command = $args[1];

            if ($this->isFlag('debug')) {
                $this->debug = true;
                Console::log("args = " . var_export($args, true));
            }

            $this->loadPlugin(DefaultPlugin::class);

            if ($this->getPackage()) {
                $this->loadPlugins();

                foreach ($this->getPackage()->getRepos() as $repo) {
                    $this->packager->getRepo()->addExternalRepoByString($repo);
                }

                $scripts = $this->packager->loadTasks($this->getPackage());

                foreach ($scripts as $bin => $handler) {
                    $invoker = Invoker::of($handler);

                    $description = Annotations::get(
                        'jppm-description',
                        $invoker->getDescription(),
                        "script " . (is_string($handler) ? $handler : '')
                    );

                    $dependsOn = Annotations::get('jppm-depends-on', $invoker->getDescription(), []);

                    $this->addCommand($bin, function ($args) use ($invoker, $handler) {
                        $invoker->call(new Event($this->packager, $this->getPackage(), $args));
                    }, $description, $dependsOn);
                }
            }

            $this->invokeTask($command, flow($args)->skip(2)->toArray(), ...flow($this->flags)->keys());
        } finally {
            Timer::shutdownAll();
        }
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    function invokeTask(string $task, array $args, ...$flags)
    {
        if ($this->taskUpDate[$task . '#' . str::join($flags, ',')]) {
            Console::log("\r[$task] Skip (up-to-date)");
            return;
        }

        $flags = arr::combine($flags, $flags);

        $this->taskUpDate[$task] = true;

        switch ($task) {
            case "version":
                Console::log('JPHP Packager Welcome');
                Console::log("-> version: {0}", $this->packager->getVersion());
                Console::log("-> jphp version: {0}", JPHP_VERSION);
                Console::log("-> home dir: {0}", System::getProperty("jppm.home"));
                break;

            default:
                $command = $this->commands[$task];

                if ($handler = $command['handler']) {
                    foreach ($command['dependsOn'] as $one) {
                        $this->invokeTask($one, $args);
                    }

                    Console::log("-> {0} {1}", $task, ($flags ? '-' : '') . flow($flags)->keys()->toString(' -'));

                    $handler($args, $flags);
                    break;
                } else {
                    $means = [];

                    foreach ($this->commands as $name => $info) {
                        $name = new TextWord($name);

                        if ($name->jaroWinklerDistance($task) > 0.9) {
                            $means[] = "$name";
                        }
                    }

                    Console::error("Task '{0}' not found. Try to run 'jppm tasks' to show all available tasks.", $task);


                    if ($means) {
                        Console::log("\n   The most similar command is:\n");
                        foreach ($means as $mean) {
                            Console::log("     - {0}", $mean);
                        }
                    }

                    exit(-1);
                }
        }
    }

    protected function loadPlugin($plugin)
    {
        if (class_exists("{$plugin}Plugin")) {
            $plugin = "{$plugin}Plugin";
        }

        if (class_exists($plugin)) {
            $class = new \ReflectionClass($plugin);
            $prefix = Annotations::getOfClass('jppm-task-prefix', $class, "");
            $tasks = Annotations::getOfClass('jppm-task', $class, []);

            $pluginObject = null;

            foreach ($tasks as $task) {
                [$task, $taskName] = str::split($task, ' as ');
                $task = str::trim($task);
                $taskName = str::trim($taskName ?? $task);

                if (method_exists($plugin, $task)) {
                    $context = null;
                    $handler = new \ReflectionMethod($plugin, $task);

                    if (!$handler->isStatic()) {
                        if (!$pluginObject) {
                            $pluginObject = new $plugin(new Event($this->packager, $this->getPackage(), []));
                        }

                        $context = $pluginObject;
                    }

                    $description = Annotations::getOfMethod('jppm-description', $handler, "$plugin::$task");
                    $dependsOn = Annotations::getOfMethod('jppm-depends-on', $handler, []);

                    $this->addCommand($prefix ? "$prefix:$taskName" : $taskName, function ($args, $flags = []) use ($handler, $context) {
                        $flags = flow($this->flags, $flags)->toMap();

                        $handler->invokeArgs($context, [new Event($this->packager, $this->getPackage(), $args, $flags)]);
                    }, $description, $dependsOn);
                } else {
                    Console::warn("Cannot add task '{0}', method '{1}' not found in '{2}'", $taskName, $task, $plugin);
                }
            }
        } else {
            Console::error("Incorrect plugin '{0}', class not found.", $plugin);
        }
    }

    protected function loadPlugins()
    {
        if ($this->getPackage()) {
            $plugins = $this->getPackage()->getAny('plugins', []);

            foreach ($plugins as $key => $plugin) {
                $this->loadPlugin($plugin);
            }
        }
    }

    function isFlag(...$names): bool
    {
        foreach ($names as $name) {
            if ($this->flags[$name]) return true;
        }

        return false;
    }

    function fail(string $massage, int $status = -1)
    {
        $stderr = Stream::of("php://stderr");
        $stderr->write($massage);

        exit($status);
    }

    /**
     * @return callable[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    function getPackage(): ?Package
    {
        try {
            $dir = fs::abs("./");
            return $this->packager->getRepo()->readPackage("$dir/" . Package::FILENAME);
        } catch (IOException $e) {
            return null;
        }
    }

    function addCommand(string $name, callable $handle, string $description = '', array $dependsOn = [])
    {
        $this->commands[$name] = ['handler' => $handle, 'description' => $description, 'dependsOn' => $dependsOn];
    }
}