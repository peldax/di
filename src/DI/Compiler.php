<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\DI;

use Nette;


/**
 * DI container compiler.
 */
class Compiler
{
	use Nette\SmartObject;

	private const
		SERVICES = 'services',
		PARAMETERS = 'parameters';

	/** @var CompilerExtension[] */
	private $extensions = [];

	/** @var ContainerBuilder */
	private $builder;

	/** @var Config\Processor */
	private $configProcessor;

	/** @var array */
	private $config = [];

	/** @var array [section => array[]] */
	private $configs = [];

	/** @var string */
	private $sources = '';

	/** @var DependencyChecker */
	private $dependencies;

	/** @var string */
	private $className = 'Container';


	public function __construct(ContainerBuilder $builder = null)
	{
		$this->builder = $builder ?: new ContainerBuilder;
		$this->dependencies = new DependencyChecker;
		$this->configProcessor = new Config\Processor($this->builder);
		$this->addExtension(self::SERVICES, new Extensions\ServicesExtension);
		$this->addExtension(self::PARAMETERS, new Extensions\ParametersExtension(function () {
			$this->configs = Helpers::expand(array_diff_key($this->configs, [self::SERVICES => 1]), $this->builder->parameters) + $this->configs;
		}));
	}


	/**
	 * Add custom configurator extension.
	 * @return static
	 */
	public function addExtension(?string $name, CompilerExtension $extension)
	{
		if ($name === null) {
			$name = '_' . count($this->extensions);
		} elseif (isset($this->extensions[$name])) {
			throw new Nette\InvalidArgumentException("Name '$name' is already used or reserved.");
		}
		$lname = strtolower($name);
		foreach (array_keys($this->extensions) as $nm) {
			if ($lname === strtolower((string) $nm)) {
				throw new Nette\InvalidArgumentException("Name of extension '$name' has the same name as '$nm' in a case-insensitive manner.");
			}
		}
		$this->extensions[$name] = $extension->setCompiler($this, $name);
		return $this;
	}


	public function getExtensions(string $type = null): array
	{
		return $type
			? array_filter($this->extensions, function ($item) use ($type): bool { return $item instanceof $type; })
			: $this->extensions;
	}


	public function getContainerBuilder(): ContainerBuilder
	{
		return $this->builder;
	}


	/**
	 * @return static
	 */
	public function setClassName(string $className)
	{
		$this->className = $className;
		return $this;
	}


	/**
	 * Adds new configuration.
	 * @return static
	 */
	public function addConfig(array $config)
	{
		foreach ($config as $section => $data) {
			$this->configs[$section][] = $data;
		}
		$this->sources .= "// source: array\n";
		return $this;
	}


	/**
	 * Adds new configuration from file.
	 * @return static
	 */
	public function loadConfig(string $file, Config\Loader $loader = null)
	{
		$sources = $this->sources . "// source: $file\n";
		$loader = $loader ?: new Config\Loader;
		foreach ($loader->load($file, false) as $data) {
			$this->addConfig($data);
		}
		$this->dependencies->add($loader->getDependencies());
		$this->sources = $sources;
		return $this;
	}


	/**
	 * Returns configuration.
	 * @deprecated
	 */
	public function getConfig(): array
	{
		return $this->config;
	}


	/**
	 * Sets the names of dynamic parameters.
	 * @return static
	 */
	public function setDynamicParameterNames(array $names)
	{
		$this->extensions[self::PARAMETERS]->dynamicParams = $names;
		return $this;
	}


	/**
	 * Adds dependencies to the list.
	 * @param  array  $deps  of ReflectionClass|\ReflectionFunctionAbstract|string
	 * @return static
	 */
	public function addDependencies(array $deps)
	{
		$this->dependencies->add(array_filter($deps));
		return $this;
	}


	/**
	 * Exports dependencies.
	 */
	public function exportDependencies(): array
	{
		return $this->dependencies->export();
	}


	public function compile(): string
	{
		$this->processExtensions();
		$this->processServices();
		return $this->generateCode();
	}


	/** @internal */
	public function processExtensions(): void
	{
		$first = $this->getExtensions(Extensions\ParametersExtension::class) + $this->getExtensions(Extensions\ExtensionsExtension::class);
		foreach ($first as $name => $extension) {
			$config = $this->processSchema($extension->getConfigSchema(), $this->configs[$name] ?? [], $name);
			$extension->setConfig($this->config[$name] = $config);
			$extension->loadConfiguration();
		}

		$last = $this->getExtensions(Extensions\InjectExtension::class);
		$this->extensions = array_merge(array_diff_key($this->extensions, $last), $last);

		$extensions = array_diff_key($this->extensions, $first);
		foreach ($extensions as $name => $extension) {
			$config = $this->processSchema($extension->getConfigSchema(), $this->configs[$name] ?? [], $name);
			$extension->setConfig($this->config[$name] = $config);
		}

		foreach ($extensions as $extension) {
			$extension->loadConfiguration();
		}

		if ($extra = array_diff_key($this->extensions, $extensions, $first)) {
			$extra = implode("', '", array_keys($extra));
			throw new Nette\DeprecatedException("Extensions '$extra' were added while container was being compiled.");

		} elseif ($extra = key(array_diff_key($this->configs, $this->extensions))) {
			$hint = Nette\Utils\ObjectHelpers::getSuggestion(array_keys($this->extensions), $extra);
			throw new InvalidConfigurationException(
				"Found section '$extra' in configuration, but corresponding extension is missing"
				. ($hint ? ", did you mean '$hint'?" : '.')
			);
		}
	}


	/** @internal */
	public function processServices(): void
	{
		if (isset($this->configs[self::SERVICES])) {
			$schema = Nette\Schema\Expect::arrayOf(new Config\DefinitionSchema($this->builder));
			$config = $this->processSchema($schema, $this->configs[self::SERVICES] ?? [], self::SERVICES);
			$this->config[self::SERVICES] = $config;
			$this->configProcessor->loadDefinitions($config);
		}
	}


	/**
	 * Merges and validates configurations against scheme.
	 * @return array|object
	 */
	private function processSchema(Nette\Schema\Schema $schema, array $configs, $name = null)
	{
		$context = new Nette\Schema\Context;
		$context->path = $name ? [$name] : [];
		try {
			return (new Nette\Schema\Processor)->processMultiple($schema, $configs, $context);
		} catch (Nette\Schema\ValidationException $e) {
			throw new Nette\DI\InvalidConfigurationException($e->getMessage());
		}
	}


	/** @internal */
	public function generateCode(): string
	{
		$this->builder->resolve();

		foreach ($this->extensions as $extension) {
			$extension->beforeCompile();
			$this->dependencies->add([(new \ReflectionClass($extension))->getFileName()]);
		}

		$this->builder->complete();

		$generator = new PhpGenerator($this->builder);
		$class = $generator->generate($this->className);
		$class->addMethod('initialize');
		$this->dependencies->add($this->builder->getDependencies());

		foreach ($this->extensions as $extension) {
			$extension->afterCompile($class);
		}

		return $this->sources . "\n" . $generator->toString($class);
	}


	/**
	 * Loads list of service definitions from configuration.
	 */
	public function loadDefinitionsFromConfig(array $configList): void
	{
		$schema = Nette\Schema\Expect::arrayOf(new Config\DefinitionSchema($this->builder));
		$config = $this->processSchema($schema, [$configList]);
		$this->configProcessor->loadDefinitions($config);
	}


	/**
	 * @deprecated use non-static Compiler::loadDefinitionsFromConfig()
	 */
	public static function loadDefinitions(): void
	{
		throw new Nette\DeprecatedException(__METHOD__ . '() is deprecated, use non-static Compiler::loadDefinitionsFromConfig(array $configList).');
	}


	/**
	 * @deprecated use non-static Compiler::loadDefinitionsFromConfig()
	 */
	public static function loadDefinition(): void
	{
		throw new Nette\DeprecatedException(__METHOD__ . '() is deprecated, use non-static Compiler::loadDefinitionsFromConfig(array $configList).');
	}
}
