<?php
/**
 * Provides english word inflection, notation conversion, grammar helpers and internationlization support
 * 
 * @copyright  Copyright (c) 2007-2009 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fGrammar
 * 
 * @version    1.0.0b2
 * @changes    1.0.0b3  Changed replacement values in preg_replace() calls to be properly escaped [wb, 2009-06-11]
 * @changes    1.0.0b2  Fixed a bug where some words would lose capitalization with ::pluralize() and ::singularize() [wb, 2009-01-25]
 * @changes    1.0.0b   The initial implementation [wb, 2007-09-25]
 */
class fGrammar
{
	// The following constants allow for nice looking callbacks to static methods
	const addCamelUnderscoreRule    = 'fGrammar::addCamelUnderscoreRule';
	const addHumanizeRule           = 'fGrammar::addHumanizeRule';
	const addSingularPluralRule     = 'fGrammar::addSingularPluralRule';
	const camelize                  = 'fGrammar::camelize';
	const humanize                  = 'fGrammar::humanize';
	const inflectOnQuantity         = 'fGrammar::inflectOnQuantity';
	const joinArray                 = 'fGrammar::joinArray';
	const pluralize                 = 'fGrammar::pluralize';
	const registerJoinArrayCallback = 'fGrammar::registerJoinArrayCallback';
	const reset                     = 'fGrammar::reset';
	const singularize               = 'fGrammar::singularize';
	const underscorize              = 'fGrammar::underscorize';
	
	
	/**
	 * Custom rules for camelizing a string
	 * 
	 * @var array
	 */
	static private $camelize_rules = array();
	
	/**
	 * Custom rules for humanizing a string
	 * 
	 * @var array
	 */
	static private $humanize_rules = array();
	
	/**
	 * The callback to replace ::joinArray()
	 * 
	 * @var callback
	 */
	static private $join_array_callback = NULL;
	
	/**
	 * Rules for plural to singular inflection of nouns
	 * 
	 * @var array
	 */
	static private $plural_to_singular_rules = array(
		'([ml])ice'                    => '\1ouse',
		'(media|info(rmation)?|news)$' => '\1',
		'(q)uizzes$'                   => '\1uiz',
		'(c)hildren$'                  => '\1hild',
		'(p)eople$'                    => '\1erson',
		'(m)en$'                       => '\1an',
		'((?!sh).)oes$'                => '\1o',
		'((?<!o)[ieu]s|[ieuo]x)es$'    => '\1',
		'([cs]h)es$'                   => '\1',
		'(ss)es$'                      => '\1',
		'([aeo]l)ves$'                 => '\1f',
		'([^d]ea)ves$'                 => '\1f',
		'(ar)ves$'                     => '\1f',
		'([nlw]i)ves$'                 => '\1fe',
		'([aeiou]y)s$'                 => '\1',
		'([^aeiou])ies$'               => '\1y',
		'(la)ses$'                     => '\1s',
		'(.)s$'                        => '\1'
	);
	
	/**
	 * Rules for singular to plural inflection of nouns
	 * 
	 * @var array
	 */
	static private $singular_to_plural_rules = array(
		'([ml])ouse$'                  => '\1ice',
		'(media|info(rmation)?|news)$' => '\1',
		'(phot|log)o$'                 => '\1os',
		'^(q)uiz$'                     => '\1uizzes',
		'(c)hild$'                     => '\1hildren',
		'(p)erson$'                    => '\1eople',
		'(m)an$'                       => '\1en',
		'([ieu]s|[ieuo]x)$'            => '\1es',
		'([cs]h)$'                     => '\1es',
		'(ss)$'                        => '\1es',
		'([aeo]l)f$'                   => '\1ves',
		'([^d]ea)f$'                   => '\1ves',
		'(ar)f$'                       => '\1ves',
		'([nlw]i)fe$'                  => '\1ves',
		'([aeiou]y)$'                  => '\1s',
		'([^aeiou])y$'                 => '\1ies',
		'([^o])o$'                     => '\1oes',
		's$'                           => 'ses',
		'(.)$'                         => '\1s'
	);
	
	/**
	 * Custom rules for underscorizing a string
	 * 
	 * @var array
	 */
	static private $underscorize_rules = array();
	
	
	/**
	 * Adds a custom mapping of a non-humanized string to a humanized string for ::humanize()
	 * 
	 * @param  string $non_humanized_string  The non-humanized string
	 * @param  string $humanized_string      The humanized string
	 * @return void
	 */
	static public function addHumanizeRule($non_humanized_string, $humanized_string)
	{
		self::$humanize_rules[$non_humanized_string] = $humanized_string;
	}
	
	
	/**
	 * Adds a custom `camelCase` to `underscore_notation` and `underscore_notation` to `camelCase` rule
	 * 
	 * @param  string $camel_case           The lower `camelCase` version of the string
	 * @param  string $underscore_notation  The `underscore_notation` version of the string
	 * @return void
	 */
	static public function addCamelUnderscoreRule($camel_case, $underscore_notation)
	{
		$camel_case = strtolower($camel_case[0]) . substr($camel_case, 1);
		self::$underscorize_rules[$camel_case] = $underscore_notation;
		self::$camelize_rules[$underscore_notation] = $camel_case;
	}
	
	
	/**
	 * Adds a custom singular to plural and plural to singular rule for ::pluralize() and ::singularize()
	 * 
	 * @param  string $singular  The singular version of the noun
	 * @param  string $plural    The plural version of the noun
	 * @return void
	 */
	static public function addSingularPluralRule($singular, $plural)
	{
		self::$singular_to_plural_rules = array_merge(
			array(
				'^(' . preg_quote($singular[0], '#') . ')' . preg_quote(substr($singular, 1), '#') . '$' =>
					'\1' . strtr(substr($plural, 1), array('\\' => '\\\\', '$' => '\\$'))
			),
			self::$singular_to_plural_rules
		);
		self::$plural_to_singular_rules = array_merge(
			array(
				'^(' . preg_quote($plural[0], '#') . ')' . preg_quote(substr($plural, 1), '#') . '$' =>
					'\1' . strtr(substr($singular, 1), array('\\' => '\\\\', '$' => '\\$'))
			),
			self::$plural_to_singular_rules
		);
	}
	
	
	/**
	 * Converts an `underscore_notation`, human-friendly or `camelCase` string to `camelCase`
	 * 
	 * @param  string  $string  The string to convert
	 * @param  boolean $upper   If the camel case should be `UpperCamelCase`
	 * @return string  The converted string
	 */
	static public function camelize($string, $upper)
	{
		// Handle custom rules
		if (isset(self::$camelize_rules[$string])) {
			$camel = self::$camelize_rules[$string];
			if ($upper) {
				return strtoupper($camel[0]) . substr($camel, 1);
			}
			return $camel;	
		}
		
		// Make a humanized string like underscore notation
		if (strpos($string, ' ') !== FALSE) {
			$string = strtolower(preg_replace('#\s+#', '_', $string));
		}
		
		// Check to make sure this is not already camel case
		if (strpos($string, '_') === FALSE) {
			if ($upper) {
				$string = strtoupper($string[0]) . substr($string, 1);
			}
			return $string;
		}
		
		// Handle underscore notation
		$string = strtolower($string);
		if ($upper) {
			$string = strtoupper($string[0]) . substr($string, 1);
		}
		return preg_replace('/(_([a-z0-9]))/e', 'strtoupper("\2")', $string);
	}
	
	
	/**
	 * Makes an `underscore_notation`, `camelCase`, or human-friendly string into a human-friendly string
	 * 
	 * @param  string $string  The string to humanize
	 * @return string  The converted string
	 */
	static public function humanize($string)
	{
		if (isset(self::$humanize_rules[$string])) {
			return self::$humanize_rules[$string];	
		}
		
		// If there is a space, it is already humanized
		if (strpos($string, ' ') !== FALSE) {
			return $string;
		}
		
		// If we don't have an underscore we probably have camelCase
		if (strpos($string, '_') === FALSE) {
			$string = self::underscorize($string);
		}
		
		return preg_replace(
			'/(\b(api|css|gif|html|id|jpg|js|mp3|pdf|php|png|sql|swf|url|xhtml|xml)\b|\b\w)/e',
			'strtoupper("\1")',
			str_replace('_', ' ', $string)
		);
	}
	
	
	/**
	 * Returns the singular or plural form of the word or based on the quantity specified
	 * 
	 * @param  mixed   $quantity                     The quantity (integer) or an array of objects to count
	 * @param  string  $singular_form                The string to be returned for when `$quantity = 1`
	 * @param  string  $plural_form                  The string to be returned for when `$quantity != 1`, use `%d` to place the quantity in the string
	 * @param  boolean $use_words_for_single_digits  If the numbers 0 to 9 should be written out as words
	 * @return string
	 */
	static public function inflectOnQuantity($quantity, $singular_form, $plural_form=NULL, $use_words_for_single_digits=FALSE)
	{
		if ($plural_form === NULL) {
			$plural_form = self::pluralize($singular_form);
		}
		
		if (is_array($quantity)) {
			$quantity = sizeof($quantity);
		}
		
		if ($quantity == 1) {
			return $singular_form;
			
		} else {
			$output = $plural_form;
			
			// Handle placement of the quantity into the output
			if (strpos($output, '%d') !== FALSE) {
				
				if ($use_words_for_single_digits && $quantity < 10) {
					static $replacements = array();
					if (!$replacements) {
						$replacements = array(
							0 => self::compose('zero'),
							1 => self::compose('one'),
							2 => self::compose('two'),
							3 => self::compose('three'),
							4 => self::compose('four'),
							5 => self::compose('five'),
							6 => self::compose('six'),
							7 => self::compose('seven'),
							8 => self::compose('eight'),
							9 => self::compose('nine')
						);
					}
					$quantity = $replacements[$quantity];
				}
				
				$output = str_replace('%d', $quantity, $output);
			}
			
			return $output;
		}
	}
	
	
	/**
	 * Returns the passed terms joined together using rule 2 from Strunk & White's 'The Elements of Style'
	 * 
	 * @param  array  $strings  An array of strings to be joined together
	 * @param  string $type     The type of join to perform, `'and'` or `'or'`
	 * @return string  The terms joined together
	 */
	static public function joinArray($strings, $type)
	{
		$valid_types = array('and', 'or');
		if (!in_array($type, $valid_types)) {
			throw new fProgrammerException(
				'The type specified, %1$s, is invalid. Must be one of: %2$s.',
				$type,
				join(', ', $valid_types)
			);
		}
		
		if (self::$join_array_callback) {
			return call_user_func(self::$join_array_callback, $strings, $type);
		}
		
		settype($strings, 'array');
		$strings = array_values($strings);
		
		switch (sizeof($strings)) {
			case 0:
				return '';
				break;
			
			case 1:
				return $strings[0];
				break;
			
			case 2:
				return $strings[0] . ' ' . $type . ' ' . $strings[1];
				break;
				
			default:
				$last_string = array_pop($strings);
				return join(', ', $strings) . ' ' . $type . ' ' . $last_string;
				break;
		}
	}
	
	
	/**
	 * Returns the plural version of a singular noun
	 * 
	 * @param  string $singular_noun  The singular noun to pluralize
	 * @return string  The pluralized noun
	 */
	static public function pluralize($singular_noun)
	{
		list ($beginning, $singular_noun) = self::splitLastWord($singular_noun);
		foreach (self::$singular_to_plural_rules as $from => $to) {
			if (preg_match('#' . $from . '#iD', $singular_noun)) {
				return $beginning . preg_replace('#' . $from . '#iD', $to, $singular_noun);
			}
		}
		throw new fProgrammerException('The noun specified could not be pluralized');
	}
	
	
	/**
	 * Allows replacing the ::joinArray() function with a user defined function
	 * 
	 * This would be most useful for changing ::joinArray() to work with
	 * languages other than English.
	 * 
	 * @param  callback $callback  The function to replace ::joinArray() with - should accept the same parameters and return the same type
	 * @return void
	 */
	static public function registerJoinArrayCallback($callback)
	{
		if (is_string($callback) && strpos($callback, '::') !== FALSE) {
			$callback = explode('::', $callback);	
		}
		self::$join_array_callback = $callback;
	}
	
	
	/**
	 * Resets the configuration of the class
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	static public function reset()
	{
		self::$camelize_rules    = array();
		self::$humanize_rules           = array();
		self::$join_array_callback      = NULL;
		self::$plural_to_singular_rules = array(
			'([ml])ice'                    => '\1ouse',
			'(media|info(rmation)?|news)$' => '\1',
			'quizzes$'                     => 'quiz',
			'children$'                    => 'child',
			'people$'                      => 'person',
			'men$'                         => 'man',
			'((?!sh).)oes$'                => '\1o',
			'((?<!o)[ieu]s|[ieuo]x)es$'    => '\1',
			'([cs]h)es$'                   => '\1',
			'(ss)es$'                      => '\1',
			'([aeo]l)ves$'                 => '\1f',
			'([^d]ea)ves$'                 => '\1f',
			'(ar)ves$'                     => '\1f',
			'([nlw]i)ves$'                 => '\1fe',
			'([aeiou]y)s$'                 => '\1',
			'([^aeiou])ies$'               => '\1y',
			'(la)ses$'                     => '\1s',
			'(.)s$'                        => '\1'
		);
		self::$singular_to_plural_rules = array(
			'([ml])ouse$'                  => '\1ice',
			'(media|info(rmation)?|news)$' => '\1',
			'(phot|log)o$'                 => '\1os',
			'^(q)uiz$'                     => 'quizzes',
			'child$'                       => 'children',
			'person$'                      => 'people',
			'man$'                         => 'men',
			'([ieu]s|[ieuo]x)$'            => '\1es',
			'([cs]h)$'                     => '\1es',
			'(ss)$'                        => '\1es',
			'([aeo]l)f$'                   => '\1ves',
			'([^d]ea)f$'                   => '\1ves',
			'(ar)f$'                       => '\1ves',
			'([nlw]i)fe$'                  => '\1ves',
			'([aeiou]y)$'                  => '\1s',
			'([^aeiou])y$'                 => '\1ies',
			'([^o])o$'                     => '\1oes',
			's$'                           => 'ses',
			'(.)$'                         => '\1s'
		);	
	}
	
	
	/**
	 * Returns the singular version of a plural noun
	 * 
	 * @param  string $plural_noun  The plural noun to singularize
	 * @return string  The singularized noun
	 */
	static public function singularize($plural_noun)
	{
		list ($beginning, $plural_noun) = self::splitLastWord($plural_noun);
		foreach (self::$plural_to_singular_rules as $from => $to) {
			if (preg_match('#' . $from . '#iD', $plural_noun)) {
				return $beginning . preg_replace('#' . $from . '#iD', $to, $plural_noun);
			}
		}
		throw new fProgrammerException('The noun specified could not be singularized');
	}
	
	
	/**
	 * Splits the last word off of a `camelCase` or `underscore_notation` string
	 * 
	 * @param  string $string  The string to split the word from
	 * @return array  The first element is the beginning part of the string, the second element is the last word
	 */
	static private function splitLastWord($string)
	{
		// Handle strings with spaces in them
		if (strpos($string, ' ') !== FALSE) {
			return array(substr($string, 0, strrpos($string, ' ')+1), substr($string, strrpos($string, ' ')+1));
		}
		
		// Handle underscore notation
		if ($string == self::underscorize($string)) {
			if (strpos($string, '_') === FALSE) { return array('', $string); }
			return array(substr($string, 0, strrpos($string, '_')+1), substr($string, strrpos($string, '_')+1));
		}
		
		// Handle camel case
		if (preg_match('#(.*)((?<=[a-zA-Z]|^)(?:[0-9]+|[A-Z][a-z]*)|(?<=[0-9A-Z]|^)(?:[A-Z][a-z]*))$#D', $string, $match)) {
			return array($match[1], $match[2]);
		}
		
		return array('', $string);
	}
	
	
	/**
	 * Converts a `camelCase`, human-friendly or `underscore_notation` string to `underscore_notation`
	 * 
	 * @param  string $string  The string to convert
	 * @return string  The converted string
	 */
	static public function underscorize($string)
	{
		$string = strtolower($string[0]) . substr($string, 1);
		
		// Handle custom rules
		if (isset(self::$underscorize_rules[$string])) {
			return self::$underscorize_rules[$string];
		}
		
		// If the string is already underscore notation then leave it
		if (strpos($string, '_') !== FALSE) {
			return $string;
		}
		
		// Allow humanized string to be passed in
		if (strpos($string, ' ') !== FALSE) {
			return strtolower(preg_replace('#\s+#', '_', $string));
		}
		
		do {
			$old_string = $string;
			$string = preg_replace('/([a-zA-Z])([0-9])/', '\1_\2', $string);
			$string = preg_replace('/([a-z0-9A-Z])([A-Z])/', '\1_\2', $string);
		} while ($old_string != $string);
		
		return strtolower($string);
	}
	
	
	/**
	 * Forces use as a static class
	 * 
	 * @return fGrammar
	 */
	private function __construct() { }
}



/**
 * Copyright (c) 2007-2009 Will Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */