<?php

namespace Rhymix\Framework;

/**
 * The template class.
 */
class Template
{
	/**
	 * Properties for user
	 */
	public $user;

	/**
	 * Properties for internal use
	 */
	public $config;
	public $absolute_dirname;
	public $relative_dirname;
	public $filename;
	public $extension;
	public $exists;
	public $absolute_path;
	public $relative_path;
	public $cache_path;
	public $cache_enabled = true;
	public $source_type;
	public $ob_level;
	public $vars;
	protected $_fragments = [];
	protected static $_loopvars = [];

	/**
	 * Static properties
	 */
	protected static $_mtime;
	protected static $_delay_compile;

	/**
	 * Provided for compatibility with old TemplateHandler.
	 *
	 * @return self
	 */
	public static function getInstance(): self
	{
		return new self();
	}

	/**
	 * You can also call the constructor directly.
	 *
	 * @param ?string $dirname
	 * @param ?string $filename
	 * @param ?string $extension
	 * @return void
	 */
	public function __construct(?string $dirname = null, ?string $filename = null, ?string $extension = null)
	{
		// Set instance configuration to default values.
		$this->config = new \stdClass;
		$this->config->version = 1;
		$this->config->autoescape = false;
		$this->config->context = 'HTML';

		// Set user information.
		$this->user = Session::getMemberInfo() ?: new Helpers\SessionHelper();

		// Cache commonly used configurations as static properties.
		if (self::$_mtime === null)
		{
			self::$_mtime = filemtime(__FILE__);
		}
		if (self::$_delay_compile === null)
		{
			self::$_delay_compile = config('view.delay_compile') ?? 0;
		}

		// If paths were provided, initialize immediately.
		if ($dirname && $filename)
		{
			$this->_setSourcePath($dirname, $filename, $extension ?? 'auto');
		}
	}

	/**
	 * Initialize and normalize paths.
	 *
	 * @param string $dirname
	 * @param string $filename
	 * @param string $extension
	 * @return void
	 */
	protected function _setSourcePath(string $dirname, string $filename, string $extension = 'auto'): void
	{
		// Normalize the template path. Result will look like 'modules/foo/views/'
		$dirname = trim(preg_replace('@^' . preg_quote(\RX_BASEDIR, '@') . '|\./@', '', strtr($dirname, ['\\' => '/', '//' => '/'])), '/') . '/';
		$dirname = preg_replace('/[\{\}\(\)\[\]<>\$\'"]/', '', $dirname);
		$this->absolute_dirname = \RX_BASEDIR . $dirname;
		$this->relative_dirname = $dirname;

		// Normalize the filename. Result will look like 'bar/example.html'
		$filename = trim(strtr($filename, ['\\' => '/', '//' => '/']), '/');
		$filename = preg_replace('/[\{\}\(\)\[\]<>\$\'"]/', '', $filename);

		// If the filename doesn't have a typical extension and doesn't exist, try adding common extensions.
		if (!preg_match('/\.(?:html?|php)$/', $filename) && !Storage::exists($this->absolute_dirname . $filename))
		{
			if ($extension !== 'auto')
			{
				$filename .= '.' . $extension;
				$this->extension = $extension;
			}
			elseif (Storage::exists($this->absolute_dirname . $filename . '.html'))
			{
				$filename .= '.html';
				$this->extension = 'html';
				$this->exists = true;
			}
			elseif (Storage::exists($this->absolute_dirname . $filename . '.blade.php'))
			{
				$filename .= '.blade.php';
				$this->extension = 'blade.php';
				$this->exists = true;
			}
			else
			{
				$filename .= '.html';
				$this->extension = 'html';
			}
		}

		// Set the remainder of properties.
		$this->filename = $filename;
		$this->absolute_path = $this->absolute_dirname . $filename;
		$this->relative_path = $this->relative_dirname . $filename;
		if ($this->extension === null)
		{
			$this->extension = preg_match('/\.(blade\.php|[a-z]+)$/i', $filename, $m) ? $m[1] : '';
		}
		if ($this->exists === null)
		{
			$this->exists = Storage::exists($this->absolute_path);
		}
		if ($this->exists && $this->extension === 'blade.php')
		{
			$this->config->version = 2;
			$this->config->autoescape = true;
		}
		$this->source_type = preg_match('!^((?:m\.)?[a-z]+)/!', $this->relative_dirname, $match) ? $match[1] : null;
		$this->_setCachePath();
	}

	/**
	 * Set the path for the cache file.
	 *
	 * @return void
	 */
	protected function _setCachePath()
	{
		$this->cache_path = \RX_BASEDIR . 'files/cache/template/' . $this->relative_path . '.compiled.php';
		if ($this->exists)
		{
			Debug::addFilenameAlias($this->absolute_path, $this->cache_path);
		}
	}

	/**
	 * Disable caching.
	 *
	 * @return void
	 */
	public function disableCache(): void
	{
		$this->cache_enabled = false;
	}

	/**
	 * Check if the template file exists.
	 *
	 * @return bool
	 */
	public function exists(): bool
	{
		return $this->exists ? true : false;
	}

	/**
	 * Get vars.
	 *
	 * @return ?object
	 */
	public function getVars(): ?object
	{
		return $this->vars;
	}

	/**
	 * Set vars.
	 *
	 * @param array|object $vars
	 * @return void
	 */
	public function setVars($vars): void
	{
		if (is_array($vars))
		{
			$this->vars = (object)$vars;
		}
		elseif (is_object($vars))
		{
			$this->vars = $vars;
		}
		else
		{
			throw new Exception('Template vars must be an array or object');
		}
	}

	/**
	 * Compile and execute a template file.
	 *
	 * You don't need to pass any paths if you have already supplied them
	 * through the constructor. They exist for backward compatibility.
	 *
	 * $override_filename should be considered deprecated, as it is only
	 * used in faceOff (layout source editor).
	 *
	 * @param ?string $dirname
	 * @param ?string $filename
	 * @param ?string $override_filename
	 * @return string
	 */
	public function compile(?string $dirname = null, ?string $filename = null, ?string $override_filename = null)
	{
		// If paths are given, initialize now.
		if ($dirname && $filename)
		{
			$this->_setSourcePath($dirname, $filename);
		}
		if ($override_filename)
		{
			$override_filename = trim(preg_replace('@^' . preg_quote(\RX_BASEDIR, '@') . '|\./@', '', strtr($override_filename, ['\\' => '/', '//' => '/'])), '/') . '/';
			$override_filename = preg_replace('/[\{\}\(\)\[\]<>\$\'"]/', '', $override_filename);
			$this->absolute_path = \RX_BASEDIR . $override_filename;
			$this->relative_path = $override_filename;
			$this->exists = Storage::exists($this->absolute_path);
			$this->_setCachePath();
		}

		// Return error if the source file does not exist.
		if (!$this->exists)
		{
			$error_message = sprintf('Template not found: %s', $this->relative_path);
			trigger_error($error_message, \E_USER_WARNING);
			return escape($error_message);
		}

		// Record the starting time.
		$start = microtime(true);

		// Find the latest mtime of the source template and the template parser.
		$filemtime = filemtime($this->absolute_path);
		if ($filemtime > time() - self::$_delay_compile)
		{
			$latest_mtime = self::$_mtime;
		}
		else
		{
			$latest_mtime = max($filemtime, self::$_mtime);
		}

		// If a cached result does not exist, or if it is stale, compile again.
		if (!Storage::exists($this->cache_path) || filemtime($this->cache_path) < $latest_mtime || !$this->cache_enabled)
		{
			$content = $this->parse();
			if (!Storage::write($this->cache_path, $content))
			{
				throw new Exception('Cannot write template cache file: ' . $this->cache_path);
			}
		}

		$output = $this->execute();

		// Record the time elapsed.
		$elapsed_time = microtime(true) - $start;
		if (!isset($GLOBALS['__template_elapsed__']))
		{
			$GLOBALS['__template_elapsed__'] = 0;
		}
		$GLOBALS['__template_elapsed__'] += $elapsed_time;

		return $output;
	}

	/**
	 * Compile a template and return the PHP code.
	 *
	 * @param string $dirname
	 * @param string $filename
	 * @return string
	 */
	public function compileDirect(string $dirname, string $filename): string
	{
		// Initialize paths. Return error if file does not exist.
		$this->_setSourcePath($dirname, $filename);
		if (!$this->exists)
		{
			$error_message = sprintf('Template not found: %s', $this->relative_path);
			trigger_error($error_message, \E_USER_WARNING);
			return escape($error_message);
		}

		// Parse the template, but don't actually execute it.
		return $this->parse();
	}

	/**
	 * Convert template code to PHP using a version-specific parser.
	 *
	 * Directly passing $content as a string is not available as an
	 * official API. It only exists for unit testing.
	 *
	 * @return string
	 */
	public function parse(?string $content = null): string
	{
		// Read the source, or use the provided content.
		if ($content === null && $this->exists)
		{
			$content = Storage::read($this->absolute_path);
			$content = trim($content) . PHP_EOL;
		}
		if ($content === null || $content === '' || $content === PHP_EOL)
		{
			return '';
		}

		// Remove UTF-8 BOM and convert CRLF to LF.
		$content = preg_replace(['/^\xEF\xBB\xBF/', '/\r\n/'], ['', "\n"], $content);

		// Check the config tag: <config version="2" /> or <config autoescape="on" />
		$content = preg_replace_callback('!(?<=^|\n)<config\s+(\w+)="([^"]+)"\s*/?>!', function($match) {
			$this->config->{$match[1]} = ($match[1] === 'version' ? intval($match[2]) : toBool($match[2]));
			return sprintf('<?php $this->config->%s = %s; ?>', $match[1], var_export($this->config->{$match[1]}, true));
		}, $content);

		// Check the alternative version directive: @version(2)
		$content = preg_replace_callback('!(?<=^|\n)@version\s?\(([0-9]+)\)!', function($match) {
			$this->config->version = intval($match[1]);
			return sprintf('<?php $this->config->version = %s; ?>', var_export($this->config->version, true));
		}, $content);

		// Call a version-specific parser to convert template code into PHP.
		$class_name = '\Rhymix\Framework\Parsers\Template\TemplateParser_v' . $this->config->version;
		$parser = new $class_name;
		$content = $parser->convert($content, $this);

		return $content;
	}

	/**
	 * Execute the converted template and return the output.
	 *
	 * @return string
	 */
	public function execute(): string
	{
		// Import Context and lang as local variables.
		$__Context = $this->vars ?: \Context::getAll();

		// Start the output buffer.
		$this->ob_level = ob_get_level();
		ob_start();

		// Include the compiled template.
		include $this->cache_path;

		// Fetch the content of the output buffer until the buffer level is the same as before.
		$content = '';
		while (ob_get_level() > $this->ob_level)
		{
			$content .= ob_get_clean();
		}

		// Insert comments for debugging.
		if(Debug::isEnabledForCurrentUser() && \Context::getResponseMethod() === 'HTML' && !preg_match('/^<(?:\!DOCTYPE|\?xml)/', $content))
		{
			$meta = '<!--#Template%s:' . $this->relative_path . '-->' . "\n";
			$content = sprintf($meta, 'Start') . $content . sprintf($meta, 'End');
		}

		return $content;
	}

	/**
	 * Get a fragment of the executed output.
	 *
	 * @param string $name
	 * @return ?string
	 */
	public function getFragment(string $name): ?string
	{
		if (isset($this->_fragments[$name]))
		{
			return $this->_fragments[$name];
		}
		else
		{
			return null;
		}
	}

	/**
	 * Check if a path should be treated as relative to the path of the current template.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isRelativePath(string $path): bool
	{
		return !preg_match('#^((?:https?|file|data):|[\/\{<])#i', $path);
	}

	/**
	 * Convert a relative path using the given basepath.
	 *
	 * @param string $path
	 * @param string $basepath
	 * @return string
	 */
	public function convertPath(string $path, string $basepath): string
	{
		// Path relative to the Rhymix installation directory?
		if (preg_match('#^\^/?(\w.+)$#s', $path, $match))
		{
			$path = \RX_BASEURL . $match[1];
		}

		// Other paths will be relative to the given basepath.
		else
		{
			$path = preg_replace('#/\./#', '/', $basepath . $path);
		}

		// Remove extra slashes and parent directory references.
		$path = preg_replace('#\\\\#', '/', $path);
		$path = preg_replace('#//#', '/', $path);
		while (($tmp = preg_replace('#/[^/]+/\.\./#', '/', $path)) !== $path)
		{
			$path = $tmp;
		}
		return $path;
	}

	/**
	 * =================== HELPER FUNCTIONS FOR TEMPLATE v2 ===================
	 */

	/**
	 * Include another template from v2 @include directive.
	 *
	 * Blade has several variations of the @include directive, and we need
	 * access to the actual PHP args in order to process them accurately.
	 * So we do this in the Template class, not in the converter.
	 *
	 * @param ...$args
	 * @return string
	 */
	protected function _v2_include(...$args): string
	{
		// Set some basic information.
		$directive = $args[0];
		$extension = $this->extension === 'blade.php' ? 'blade.php' : null;
		$isConditional = in_array($directive, ['includeWhen', 'includeUnless']);
		$basedir = $this->relative_dirname;
		$cond = $isConditional ? $args[1] : null;
		$path = $isConditional ? $args[2] : $args[1];
		$vars = $isConditional ? ($args[3] ?? null) : ($args[2] ?? null);

		// Handle paths relative to the Rhymix installation directory.
		if (preg_match('#^\^/?(\w.+)$#s', $path, $match))
		{
			$basedir = str_contains($match[1], '/') ? dirname($match[1]) : \RX_BASEDIR;
			$path = basename($match[1]);
		}

		// If the conditions are not met, return.
		if ($isConditional && $directive === 'includeWhen' && !$cond)
		{
			return '';
		}
		if ($isConditional && $directive === 'includeUnless' && $cond)
		{
			return '';
		}

		// Create a new instance of TemplateHandler.
		$template = new self($basedir, $path, $extension);

		// If the directive is @includeIf and the template file does not exist, return.
		if ($directive === 'includeIf' && !$template->exists())
		{
			return '';
		}

		// Set variables.
		if ($vars !== null)
		{
			$template->setVars($vars);
		}

		// Compile and return.
		return $template->compile();
	}

	/**
	 * Load a resource from v2 @load directive.
	 *
	 * The Blade-style syntax does not have named arguments, so we must rely
	 * on the position and format of each argument to guess what it is for.
	 * Fortunately, there are only a handful of valid options for the type,
	 * media, and index attributes.
	 *
	 * @param ...$args
	 * @return void
	 */
	protected function _v2_loadResource(...$args): void
	{
		// Assign the path.
		$path = null;
		if (count($args))
		{
			$path = array_shift($args);
		}
		if (empty($path))
		{
			trigger_error('Resource loading directive used with no path', \E_USER_WARNING);
			return;
		}

		// Assign the remaining arguments to respective array keys.
		$info = [];
		while ($value = array_shift($args))
		{
			if (preg_match('#^([\'"])(head|body)\1$#', $value, $match))
			{
				$info['type'] = $match[2];
			}
			elseif (preg_match('#^([\'"])((?:screen|print)[^\'"]*)\1$#', $value, $match))
			{
				$info['media'] = $match[2];
			}
			elseif (preg_match('#^([\'"])([0-9]+)\1$#', $value, $match))
			{
				$info['index'] = $match[2];
			}
			elseif (ctype_digit($value))
			{
				$info['index'] = $value;
			}
			else
			{
				$info['vars'] = $value;
			}
		}

		// Check whether the path is an internal or external link.
		$external = false;
		if (preg_match('#^\^#', $path))
		{
			$path = './' . ltrim($path, '^/');
		}
		elseif ($this->isRelativePath($path))
		{
			$path = $this->convertPath($path, './' . $this->relative_dirname);
		}
		else
		{
			$external = true;
		}

		// Determine the type of resource.
		if (!$external && str_starts_with($path, './common/js/plugins/'))
		{
			$type = 'jsplugin';
		}
		elseif (!$external && preg_match('#/lang(\.xml)?$#', $path))
		{
			$type = 'lang';
		}
		elseif (preg_match('#\.(css|js|scss|less)($|\?|/)#', $path, $match))
		{
			$type = $match[1];
		}
		elseif (preg_match('#/css\d?\?.+#', $path))
		{
			$type = 'css';
		}
		else
		{
			$type = 'unknown';
		}

		// Load the resource.
		if ($type === 'jsplugin')
		{
			if (preg_match('#/common/js/plugins/([^/]+)#', $path, $match))
			{
				$plugin_name = $match[1];
				\Context::loadJavascriptPlugin($plugin_name);
			}
			else
			{
				trigger_error("Unable to find JS plugin at $path", \E_USER_WARNING);
			}
		}
		elseif ($type === 'lang')
		{
			$lang_dir = preg_replace('#/lang\.xml$#', '', $path);
			\Context::loadLang($lang_dir);
		}
		elseif ($type === 'js')
		{
			\Context::loadFile([
				$path,
				$info['type'] ?? '',
				$external ? $this->source_type : '',
				isset($info['index']) ? intval($info['index']) : '',
			]);
		}
		elseif ($type === 'css' || $type === 'scss' || $type === 'less')
		{
			\Context::loadFile([
				$path,
				$info['media'] ?? '',
				$external ? $this->source_type : '',
				isset($info['index']) ? intval($info['index']) : '',
				$info['vars'] ?? [],
			]);
		}
		else
		{
			trigger_error("Unable to determine type of resource at $path", \E_USER_WARNING);
		}
	}

	/**
	 * Initialize v2 loop variable.
	 *
	 * @param string $stack_id
	 * @param array|Traversable &$array
	 * @return object
	 */
	protected function _v2_initLoopVar(string $stack_id, &$array): object
	{
		// Create the data structure.
		$loop = new \stdClass;
		$loop->index = 0;
		$loop->iteration = 1;
		$loop->count = is_countable($array) ? count($array) : countobj($array);
		$loop->remaining = $loop->count - 1;
		$loop->first = true;
		$loop->last = ($loop->count === 1);
		$loop->even = false;
		$loop->odd = true;
		$loop->depth = count(self::$_loopvars) + 1;
		$loop->parent = count(self::$_loopvars) ? end(self::$_loopvars) : null;

		// Append to stack and return.
		return self::$_loopvars[$stack_id] = $loop;
	}

	/**
	 * Increment v2 loop variable.
	 *
	 * @param object $loopvar
	 * @return void
	 */
	protected function _v2_incrLoopVar(object $loop): void
	{
		// Update properties.
		$loop->index++;
		$loop->iteration++;
		$loop->remaining--;
		$loop->first = ($loop->count === 1);
		$loop->last = ($loop->iteration === $loop->count);
		$loop->even = ($loop->iteration % 2 === 0);
		$loop->odd = !$loop->even;
	}

	/**
	 * Remove v2 loop variable.
	 *
	 * @param object $loopvar
	 * @return void
	 */
	protected function _v2_removeLoopVar(object $loop): void
	{
		// Remove from stack.
		if ($loop === end(self::$_loopvars))
		{
			array_pop(self::$_loopvars);
		}
	}

	/**
	 * Attribute builder for v2.
	 *
	 * @param string $attribute
	 * @param array $definition
	 * @return string
	 */
	protected function _v2_buildAttribute(string $attribute, array $definition = []): string
	{
		$delimiters = [
			'class' => ' ',
			'style' => '; ',
		];

		$values = [];
		foreach ($definition as $key => $val)
		{
			if (is_int($key) && !empty($val))
			{
				$values[] = $val;
			}
			elseif ($val)
			{
				$values[] = $key;
			}
		}

		return sprintf(' %s="%s"', $attribute, escape(implode($delimiters[$attribute], $values), false));
	}

	/**
	 * Auth checker for v2.
	 *
	 * @param string $type
	 * @return bool
	 */
	protected function _v2_checkAuth(string $type = 'member'): bool
	{
		$grant = \Context::get('grant');
		switch ($type)
		{
			case 'admin': return $this->user->isAdmin();
			case 'manager': return $grant->manager ?? false;
			case 'member': return $this->user->isMember();
			default: return $grant->$type ?? false;
		}
	}
}
