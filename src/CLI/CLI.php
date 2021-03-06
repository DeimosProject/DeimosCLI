<?php

namespace Deimos\CLI;

use Deimos\CLI\Exceptions\CLIRun;
use Deimos\CLI\Exceptions\Required;
use Deimos\CLI\Exceptions\UndefinedVariable;

class CLI
{

    /**
     * @var array
     */
    protected $argv;

    /**
     * @var array
     */
    protected $variables = [];

    /**
     * @var array
     */
    protected $aliases;

    /**
     * @var array
     */
    protected $requiredList;

    /**
     * @var array
     */
    protected $storage;

    /**
     * @var array
     */
    protected $commands = [];

    /**
     * @var array
     */
    protected $errorList = [];

    /**
     * @var bool
     */
    protected $run;

    /**
     * CLI constructor.
     *
     * @param array $argv
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;

        if (!empty($this->argv))
        {
            // remove element [0] *.php
            array_shift($this->argv);
        }
    }

    /**
     * @param string $name
     *
     * @return InterfaceVariable
     */
    public function variable($name)
    {
        return ($this->variables[$name] = new Variable($name));
    }

    /**
     * @param Variable $variable
     */
    protected function required(Variable $variable)
    {
        if ($variable->isRequired())
        {
            $this->requiredList[] = $variable->name();
        }
    }

    /**
     * @param Variable $variable
     */
    protected function initAliases(Variable $variable)
    {
        // init aliases
        foreach ($variable->aliases() as $alias)
        {
            $this->aliases[$alias] = $variable;
        }
    }

    /**
     * @return string
     */
    protected function argvString()
    {
        return implode(' ', $this->argv);
    }

    /**
     * init cli
     */
    protected function init()
    {
        $this->aliases      = [];
        $this->requiredList = [];

        /**
         * @var $variable Variable
         */
        foreach ($this->variables as $variable)
        {
            $this->required($variable);
            $this->initAliases($variable);
        }
    }

    /**
     * @param $data
     *
     * @return array|mixed
     */
    protected function value($data)
    {
        return is_array($data) && isset($data[1]) ? $data[1] : $data;
    }

    /**
     * @param array $array
     */
    protected function initStorage(array &$array)
    {
        foreach ($array as $item)
        {
            $value = $this->value($item);

            if (in_array($value, ['-', '--'], false))
            {
                break;
            }

            $data = array_shift($array);

            if ($value !== ' ')
            {
                $this->storage[] = $this->value($data);
            }
        }
    }

    /**
     * @param array $array
     *
     * @throws UndefinedVariable
     */
    protected function initVariable(array &$array)
    {
        $isAlias = false;
        $isKey   = false;
        $key     = null;

        foreach ($array as $item)
        {
            $item  = array_shift($array);
            $value = $this->value($item);

            if ($value === ' ')
            {
                continue;
            }

            if (!$isKey && in_array($value, ['-', '--'], true))
            {
                $isAlias = $value === '-';
                $isKey   = true;
                continue;
            }

            if ($isKey)
            {
                $key   = $value;
                $isKey = false;

                if ($isAlias)
                {
                    if (!isset($this->aliases[$key]))
                    {
                        $this->errorList[] = new UndefinedVariable('Not found alias \'' . $key . '\'');
                    }
                }
                else if (!isset($this->variables[$key]))
                {
                    $this->errorList[] = new UndefinedVariable('Not found variable \'' . $key . '\'');
                }

                $this->commands[$key] = [];
                continue;
            }

            $stringValue = '';

            while ($value !== ' ')
            {
                if ($value === null)
                {
                    break;
                }

                $stringValue .= $value;

                $item  = array_shift($array);
                $value = $this->value($item);
            }

            if (strlen($stringValue) > 0)
            {
                $this->commands[$key][] = $stringValue;
            }

        }
    }

    /**
     * @param Variable $variable
     * @param array    $keys
     */
    protected function findAliases(Variable $variable, array $keys)
    {
        $aliasList = $variable->aliases();
        $name      = $variable->name();

        if (!isset($this->commands[$name]))
        {
            foreach ($aliasList as $alias)
            {
                if (in_array($alias, $keys, true))
                {
                    return;
                }
            }

            $this->errorList[] = new Required('Not found required argument \'' . $name . '\'');
        }

    }

    /**
     * @return bool
     * @throws Required
     */
    protected function initRequired()
    {
        $keys = array_keys($this->commands);

        foreach ($this->requiredList as $name)
        {
            /**
             * @var Variable $variable
             * @var array    $aliasList
             */
            $variable = $this->variables[$name];
            $this->findAliases($variable, $keys);

            continue;
        }

        return true;
    }

    /**
     * @throws UndefinedVariable
     */
    protected function initUndefined()
    {
        foreach ($this->commands as $command => &$data)
        {
            if (!isset($this->variables[$command]) && !isset($this->aliases[$command]))
            {
                $this->errorList[] = new UndefinedVariable('Not found variable \'' . $command . '\'');
            }
        }
    }

    protected function loadCommand(array $commands)
    {
        $this->commands = [];

        foreach ($commands as $name => $value)
        {
            /**
             * @var Variable $variable
             */
            $variable = isset($this->aliases[$name])
                ? $this->aliases[$name]
                : null;

            if ($variable === null)
            {
                if (!isset($this->variables[$name]))
                {
                    throw new \InvalidArgumentException('Not found variable/alias \'' . $name . '\'');
                }

                $variable = $this->variables[$name];
            }

            if (count($value))
            {
                if ($variable->isBoolType())
                {
                    $value = $value[0];
                }

                $variable->setValue($value);
            }
            else if ($variable->isBoolType())
            {
                $variable->setValue(true);
            }

            $valueStorage = $variable->value();

            if (!$variable->isBoolType() && is_array($valueStorage))
            {
                foreach ($valueStorage as $k => $val)
                {
                    if (in_array($val{0}, ['\'', '"'], false))
                    {
                        $valueStorage[$k] = substr($val, 1, -1);
                    }
                }
            }

            $this->commands[$variable->name()] = $variable->isBoolType()
                ? (bool)$valueStorage :
                $valueStorage;
        }

        foreach ($this->variables as $variable)
        {
            if (isset($this->commands[$variable->name()]))
            {
                if ($variable->isRequired() && empty($this->commands[$variable->name()]))
                {
                    $this->errorList[] = new UndefinedVariable(
                        'Required argument is empty \'' . $variable->name() . '\''
                    );
                }
            }
            else
            {
                $this->commands[$variable->name()] = $variable->value();
            }
        }

        $this->run = true;
    }

    protected function &commands()
    {
        if (!$this->run)
        {
            $this->errorList[] = new CLIRun('Start the run method');
        }

        return $this->commands;
    }

    /**
     * @return array
     */
    public function storage()
    {
        return $this->storage ?: [];
    }

    /**
     * @return array
     *
     * @throws CLIRun
     */
    public function asArray()
    {
        return $this->commands();
    }

    /**
     * @param string $name
     *
     * @return mixed
     *
     * @throws CLIRun
     */
    public function get($name)
    {
        $commands = $this->asArray();

        return $commands[$name];
    }

    /**
     * @param string $name
     *
     * @return mixed
     *
     * @throws CLIRun
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * @param $name
     * @param $value
     *
     * @throws \InvalidArgumentException
     */
    final public function __set($name, $value)
    {
        throw new \InvalidArgumentException(__METHOD__);
    }

    /**
     * @param $name
     *
     * @return bool
     *
     * @throws CLIRun
     */
    public function __isset($name)
    {
        $commands = &$this->commands();

        return isset($commands[$name]);
    }

    /**
     * @return array
     */
    public function help()
    {
        $variables = [];

        /**
         * @var Variable $variable
         */
        foreach ($this->variables as $variable)
        {
            $variables[] = [
                'variable' => $variable->name(),
                'help'     => $variable->getHelp(),
                'aliases'  => $variable->aliases(),
                'required' => $variable->isRequired(),
                'boolean'  => $variable->isBoolType(),
            ];
        }

        return $variables;
    }

    /**
     * @return array
     */
    protected function columns()
    {
        return [
            'Variable',
            'Aliases',
            'Required',
            'Boolean',
            'Help'
        ];
    }

    protected function toAlias(array $array)
    {
        return implode(', ', array_map(function ($alias)
        {
            return '-' . $alias;
        }, $array));
    }

    /**
     * @param array $item
     *
     * @return array
     */
    protected function row(array $item)
    {
        $variable = '--' . $item['variable'];
        $aliases  = $this->toAlias($item['aliases']);
        $required = $item['required'];
        $boolean  = $item['boolean'];
        $help     = $item['help'];

        return [$variable, $aliases, $required, $boolean, $help];
    }

    /**
     * display table
     */
    protected function _help()
    {
        $table = new Table();
        $table->setHeaders($this->columns());

        foreach ($this->help() as $key => $item)
        {
            $table->addRow($this->row($item));
        }

        die($table);
    }

    protected function message(\Exception $error)
    {
        print 'Message: ' . $error->getMessage() . PHP_EOL . PHP_EOL;

        $this->_help();
    }

    /**
     * display to cli
     */
    protected function display()
    {

        if ($this->commands['help'])
        {
            $this->_help();
        }

        $error = current($this->errorList);

        if ($error instanceof \Exception)
        {
            $this->message($error);
        }

    }

    /**
     * @param $string
     */
    protected function execute($string)
    {
        $tokenizer = new Tokenizer($string);

        try
        {
            if (PHP_SAPI !== 'cli')
            {
                throw new \InvalidArgumentException('\'PHP_SAPI\' not equal \'cli\'');
            }

            $storage = $tokenizer->run();
            $this->initStorage($storage);
            $this->initVariable($storage);
            $this->initRequired();
            $this->initUndefined();
            $this->loadCommand($this->commands);
        }
        catch (\Exception $exception)
        {
            $this->message($exception);
        }
    }

    /**
     * @return self
     *
     * @throws Required
     * @throws UndefinedVariable
     * @throws \InvalidArgumentException
     */
    public function run()
    {
        if ($this->run)
        {
            return $this;
        }

        $this->variable('help')
            ->alias('h')
            ->boolType()
            ->help('Help me! Command List');

        $this->init();

        $string = $this->argvString();
        $this->execute($string);
        $this->display();

        return $this;
    }

}