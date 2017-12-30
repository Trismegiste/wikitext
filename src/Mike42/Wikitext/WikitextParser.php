<?php
/*
 Library to add wikitext support to a web app -- http://mike.bitrevision.com/wikitext/

Copyright (C) 2012 Michael Billington <michael.billington@gmail.com>

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
associated documentation files (the "Software"), to deal in the Software without restriction,
including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial
portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A
PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/
namespace Mike42\Wikitext;

class WikitextParser
{
    const VERSION = "1.0";
    const MAX_INCLUDE_DEPTH = 32; /* Depth of template includes to put up with. set to 0 to disallow inclusion, negative to remove the limit */

    private static $inline;
    private static $lineBlock;
    public static $backend;
    private static $tableBlock;
    private static $tableStart;
    private static $inlineLookup;
    private static $preprocessor;
    private static $initialised = false;

    /* These are set as a result of parsing */
    public $preprocessed; /* Wikitext after preprocessor had a go at it. */
    public $result; /* Wikitext of result */

    /**
     * Definitions for tokens with special meaning to the parser
     */
    public static function init($sharedVars = array())
    {
        /* Table elements. These are parsed separately to the other elements */
        self::$tableStart = new ParserInlineElement("{|", "|}");

        self::$tableBlock = array(
                'tr'      => new ParserTableElement('|-', '', '', ''),
                'th'      => new ParserTableElement('!', '|', '!!', 1),
                'td'      => new ParserTableElement('|', '|', '||', 1),
                'caption' => new ParserTableElement('|+', '', '', 0));

        /* Inline elemens. These are parsed recursively and can be nested as deeply as the system will allow. */
        self::$inline = array(
                'nothing'    => new ParserInlineElement('', ''),
                'td'         => new ParserInlineElement('', ''), // Just used as a marker
                'linkInternal' => new ParserInlineElement('[[', ']]', '|', '='),
                'linkExternal' => new ParserInlineElement('[', ']', ' ', '', 1),
                'bold'       => new ParserInlineElement("'''", "'''"),
                'italic'     => new ParserInlineElement("''", "''"),
                'switch'     => new ParserInlineElement('__', '__'));

        /* Create lookup table for efficiency */
        $inlineLookup = array();
        foreach (self::$inline as $key => $token) {
            if (count($token -> startTag) != 0) {
                $c = $token -> startTag[0];
                if (!isset($inlineLookup[$c])) {
                    $inlineLookup[$c] = [];
                }
                $inlineLookup[$c][$key] = self::$inline[$key];
            }
        }
        self::$inlineLookup = $inlineLookup;
        self::$backend = new DefaultParserBackend();

        /* Line-block elements. These are characters which have a special meaning at the start of lines, and use the next end-line as a close tag. */
        self::$lineBlock = array(
                'pre' => new ParserLineBlockElement(array(" "), array(), 1, false),
                'ul'  => new ParserLineBlockElement(array("*"), array(), 0, true),
                'ol'  => new ParserLineBlockElement(array("#"), array(), 0, true),
                'dl'  => new ParserLineBlockElement(array(":", ";"), array(), 0, true),
                'h'   => new ParserLineBlockElement(array("="), array("="), 6, false));

        self::$preprocessor = array(
                'noinclude'      => new ParserInlineElement('<noinclude>', '</noinclude>'),
                'includeonly'    => new ParserInlineElement('<includeonly>', '</includeonly>'),
                'arg'            => new ParserInlineElement('{{{', '}}}', '|', '', 1),
                'template'       => new ParserInlineElement('{{', '}}', '|', '='),
                'comment'        => new ParserInlineElement('<!--', '-->'));
        
        self::$initialised = true;
    }

    /**
     * Parse a given document/page of text (main entry point)
     *
     * @param string $text
     */
    public static function parse(string $text)
    {
        $parser = new WikitextParser($text);
        return $parser -> result;
    }

    /**
     * Initialise a new parser object and parse a standalone document.
     * If templates are included, each will processed by a different instance of this object
     *
     * @param string $text The text to parse
     */
    public function __construct($text, $params = array())
    {
        if (self::$initialised == false) {
            self::init();
        }
        $this -> params = $params;
        $this -> preprocessed = $this -> preprocessText(self::explode_string($text));

        /* Now divide into paragraphs */
        $sections = explode("\n\n", str_replace("\r\n", "\n", $this -> preprocessed));

        $newtext = "";
        foreach ($sections as $section) {
            /* Newlines at the start/end have special meaning (compare to how this is called from parseLineBlock) */
            $result = $this -> parseInline("\n".$section, 'p');
            $newtext .= $result['parsed'];
        }

        $this -> result = $newtext;
    }

    private static function explode_string($string) {
        return $chrArray = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Handle template arguments and other oddities. This section of the parser is single-pass and linear, with the exception of the part which substitutes templates
     * @param string $text wikitext to handle
     * @param mixed $arg Arguments (applies only to templates)
     * @param boolean $included true if the text is included, false otherwise
     * @return string
     */
    private function preprocessText(array $textChars, $arg = array(), $included = false, $depth = 0)
    {
        $parsed = '';
        $len = count($textChars);
        for ($i = 0; $i < $len; $i++) {
            $hit = false;
                
            foreach (self::$preprocessor as $key => $child) {
                if (self::tag_is_at($child -> endTag, $textChars, $i)) {
                    if (($key == 'includeonly' && $included) || ($key == 'noinclude' && !$included)) {
                        $hit = true;
                        $i += count($child -> endTag);

                        /* Ignore expected end-tags */
                        break;
                    }
                }

                if (self::tag_is_at($child -> startTag, $textChars, $i)) {
                    /* Hit a symbol. Parse it and keep going after the result */
                    $hit = true;
                    $i += count($child -> startTag);
                        
                    if (($key == 'includeonly' && $included) || ($key == 'noinclude' && !$included)) {
                        /* If this is a good tag, ignore it! */
                        break;
                    }

                    /* Seek until end tag, looking for splitters */
                    $innerArg   = array();
                    $innerBuffer = '';
                    $innerCurKey = '';
                        
                    for ($i = $i; $i < $len; $i++) {
                        $innerHit = false;

                        if (self::tag_is_at($child -> endTag, $textChars, $i)) {
                            $i += count($child -> endTag);
                            /* Clear buffers now */
                            if ($innerCurKey == '') {
                                array_push($innerArg, $innerBuffer);
                            } else {
                                $innerArg[$innerCurKey] = $innerBuffer;
                            }

                            /* Figure out what to do with data */
                            $innerCurKey = array_shift($innerArg);
                            if ($key == 'arg') {
                                if (is_numeric($innerCurKey)) {
                                    $innerCurKey -= 1; /* Because the associative array will be starting at 0 */
                                }
                                if (isset($arg[$innerCurKey])) {
                                    $parsed .= $arg[$innerCurKey];      // Use arg value if set
                                } elseif (count($innerArg) > 0) {
                                    $parsed .= array_shift($innerArg);  // Otherwise use embedded default if set
                                }
                            } else if ($key == 'template') {
                                /* Load wikitext of template, and preprocess it */
                                if (self::MAX_INCLUDE_DEPTH < 0 || $depth < self::MAX_INCLUDE_DEPTH) {
                                    $markup = trim(self::$backend -> getTemplateMarkup($innerCurKey));
                                    $parsed .= $this -> preprocessText(self::explode_string($markup), $innerArg, true, $depth + 1);
                                }
                            }

                            $innerCurKey = ''; // Reset key
                            $innerBuffer = ''; // Reset parsed values
                            break; /* Stop inner loop(hit) */
                        }

                        /* Argument splitting -- A dumber, non-recursiver version of what is used in ParseInline() */
                        if ($child -> hasArgs && ($child -> argLimit == 0 || $child -> argLimit > count($innerArg))) {
                            if (self::tag_is_at($child -> argSep, $textChars, $i)) {
                                /* Hit argument separator */
                                if ($innerCurKey == '') {
                                    array_push($innerArg, $innerBuffer);
                                } else {
                                    $innerArg[$innerCurKey] = $innerBuffer;
                                }
                                $innerCurKey = ''; // Reset key
                                $innerBuffer = ''; // Reset parsed values
                                $i += count($child -> argSep) - 1;
                                $innerHit = true;
                            } elseif ($innerCurKey == '' && self::tag_is_at($child -> argNameSep, $textChars, $i)) {
                                /* Hit name/argument splitter */
                                $innerCurKey = $innerBuffer; // Set key
                                $innerBuffer = '';  // Reset parsed values
                                $i += count($child -> argNameSep) - 1;
                                $innerHit = true;
                            }
                        }

                        if (!$innerHit) {
                            /* Append non-matching characters to buffer as we go */
                            $innerBuffer .= $textChars[$i];
                        }
                    }
                }
            }

            /* Add non-affected characters as we go */
            if (!$hit) {
                $c = $textChars[$i];
                $parsed .= $c;
            } else {
                $i -= 1;
            }
        }
        return $parsed;
    }

    private static function tag_is_at(array $tag, array $textChars, int $position) {
        $tag_len = count($tag);
        $str_len = count($textChars);
        if($tag_len == 0 || $position + $tag_len > $str_len || $textChars[$position] != $tag[0]) { //$textChars[$position] != $tag[0]) {
            return false;
        }
        // TODO might work faster if we can ditch use of strings
        $tag = implode($tag);
        $len = mb_strlen($tag);
        $text = implode(array_slice($textChars, $position, $len));
        return $len !== 0 && $tag == $text;
    }

    /**
     * Parse a block of wikitext looking for inline tokens, indicating the start of an element.
     * Calls itself recursively to search inside those elements when it finds them
     *
     * @param string $text Text to parse
     * @param $token The name of the current inline element, if inside one.
     */
    private function parseInline(string $text, string $token = '')
    {
        /* Quick escape if we've run into a table */
        $inParagraph = false;
        if ($token == '' || !isset(self::$inline[$token])) {
            /* Default to empty token if none is set (these have no end token, ensuring there will be no remainder after this runs) */
            if ($token == 'p') {
                /* Blocks of text here need to be encapsualted in paragraph tags */
                $inParagraph = true;
            }
            $inlineElement = self::$inline['nothing'];
        } else {
            $inlineElement = self::$inline[$token];
        }

        $parsed = ''; // For completely parsed text
        $buffer = ''; // For text which may still be encapsulated or chopped up
        $remainder = '';

        $arg = array();
        $curKey = '';

        $textChars = self::explode_string($text);
        $len = count($textChars);
        for ($i = 0; $i < $len; $i++) {
            /* Looping through each character */
            $hit = false; // State so that the last part knows whether to simply append this as an unmatched character
                
            /* Looking for this element's close-token */
            if (self::tag_is_at($inlineElement -> endTag, $textChars, $i)) {
                //mb_strlen($inlineElement -> endTag) != 0 && $inlineElement -> endTag == mb_substr($text, $i, mb_strlen($inlineElement -> endTag))) {
                /* Hit a close tag: Stop parsing here, return the remainder, and let the parent continue */
                $start = $i + count($inlineElement -> endTag);
                $remainder = mb_substr($text, $start, $len - $start);

                if ($inlineElement -> hasArgs) {
                    /* Handle arguments if needed */
                    if ($curKey == '') {
                        array_push($arg, $buffer);
                    } else {
                        $arg[$curKey] = $buffer;
                    }
                    $buffer = self::$backend -> renderWithArgs($token, $arg);
                }

                /* Clean up and quit */
                $parsed .= $buffer; /* As far as I can tall $inPargraph should always be false here? */
                return array('parsed' => $parsed, 'remainder' => $remainder);
            }
                
            /* Next priority is looking for this element's agument tokens if applicable */
            if ($inlineElement -> hasArgs && ($inlineElement -> argLimit == 0 || $inlineElement -> argLimit > count($arg))) {
                if (self::tag_is_at($inlineElement -> argSep, $textChars, $i)) {
                    // mb_strlen($inlineElement -> argSep) != 0 && $inlineElement -> argSep == mb_substr($text, $i, mb_strlen($inlineElement -> argSep))) {
                    /* Hit argument separator */
                    if ($curKey == '') {
                        array_push($arg, $buffer);
                    } else {
                        $arg[$curKey] = $buffer;
                    }
                        
                    $curKey = ''; // Reset key
                    $buffer = ''; // Reset parsed values
                    /* Handle position properly */
                    $i += count($inlineElement -> argSep) - 1;
                    $hit = true;
                } elseif ($curKey == '' && self::tag_is_at($inlineElement -> argNameSep, $textChars, $i)) {
                    //mb_strlen($inlineElement -> argNameSep) != 0 && $inlineElement -> argNameSep == mb_substr($text, $i, mb_strlen($inlineElement -> argNameSep))) {
                    /* Hit name/argument splitter */
                    $curKey = $buffer; // Set key
                    $buffer = '';  // Reset parsed values
                    /* Handle position properly */
                    $i += count($inlineElement -> argNameSep) - 1;
                    $hit = true;
                }
            }

            /* Looking for new open-tokens */
            $c = $textChars[$i];
            if (isset(self::$inlineLookup[$c])) {
                /* There are inline elements which start with this character. Check each one,.. */
                foreach (self::$inlineLookup[$c] as $key => $child) {
                    if (!$hit && self::tag_is_at($child -> startTag, $textChars, $i)) {
                        //mb_strlen($child -> startTag) != 0 && $child -> startTag == mb_substr($text, $i, mb_strlen($child -> startTag))) {
                        /* Hit a symbol. Parse it and keep going after the result */
                        $start = $i + count($child -> startTag);
                        $remainder = mb_substr($text, $start, $len - $start);

                        /* Regular, recursively-parsed element */
                        $result = $this -> parseInline($remainder, $key);
                        $buffer .= self::$backend -> encapsulateElement($key, $result['parsed']);

                        $text = $result['remainder'];
                        $textChars = self::explode_string($text);
                        $len = count($textChars);
                        $i = -1;
                        $hit = true;
                    }
                }
            }

            if (!$hit) {
                if ($c == "\n") {
                    if (self::tag_is_at(self::$tableStart -> startTag, $textChars, $i + 1)) {
                        //self::$tableStart -> startTag == mb_substr($text, $i + 1, mb_strlen(self::$tableStart -> startTag))) {
                        $hit = true;
                        $start = $i + 1 + count(self::$tableStart -> startTag);
                        $key = 'table';
                    } else if ($i < $len - 1){
                        /* Check for non-table line-based stuff coming up next, each time \n is found */
                        $next = $textChars[$i + 1];
                        foreach (self::$lineBlock as $key => $block) {
                            foreach ($block -> startChar as $char) {
                                if (!$hit && $next == $char) {
                                    $hit = true;
                                    $start = $i + 1;
                                    break 2;
                                }
                            }
                        }
                    }
                        
                    if ($hit) {
                        /* Go over what's been found */
                        $remainder = mb_substr($text, $start, $len - $start);

                        if ($key == 'table') {
                            $result = $this -> parseTable($remainder);
                        } else {
                            /* Let parseLineBlock take care of this on a per-line basis */
                            $result = $this -> parseLineBlock($remainder, $key);
                        }

                        if ($buffer != '') {
                            /* Something before this was part of a paragraph */
                            $parsed .= self::$backend -> encapsulateElement('paragraph', $buffer);
                            $inParagraph == true;
                        }
                        $buffer = "";
                        /* Now append this non-paragraph element */
                        $parsed .= $result['parsed'];
                            
                        /* Same sort of thing as above */
                        $text = $result['remainder'];
                        $textChars = self::explode_string($text);
                        $len = count($textChars);
                        $i = -1;
                    }

                    /* Other \n-related things if it wasn't as exciting as above */
                    if ($buffer != '' && !$hit) {
                        /* Put in a space if it is not going to be the first thing added. */
                        $buffer .= " ";
                    }
                } else {
                    /* Append character to parsed output if it was not part of some token */
                    $buffer .= $c;
                }
            }
                
            if ($token == 'td') {
                /* We only get here from table syntax if something else was being parsed, so we can quit here */
                $parsed = $buffer;
                return array('parsed' => $parsed, 'remainder' => $text);
            }
        }

        /* Need to throw argument-driven items at the backend first here */
        if ($inlineElement -> hasArgs) {
            if ($curKey == '') {
                array_push($arg, $buffer);
            } else {
                $arg[$curKey] = $buffer;
            }
            $buffer = self::$backend -> renderWithArgs($token, $arg);
        }

        if ($inParagraph && $buffer != '') {
            /* Something before this was part of a paragraph */
            $parsed .= self::$backend -> encapsulateElement('paragraph', $buffer);
        } else {
            $parsed .= $buffer;
        }

        return array('parsed' => $parsed, 'remainder' => '');
    }

    /**
     * Parse block of wikitext known to be starting with a line-based token
     *
     * @param $text Wikitext block to parse
     * @param $token name of the LineBlock token which we suspect
     */
    private function parseLineBlock($text, $token)
    {
        /* Block element we are using */
        $lineBlockElement = self::$lineBlock[$token];

        $lines = explode("\n", $text);
        /* Raw array of list items and their depth */
        $list = array();

        while (count($lines) > 0) {
            /* Loop through lines */
            $count = 0;
            $char = '';
                
            $count = self::countChar($lineBlockElement -> startChar, $lines[0], $lineBlockElement -> limit);
            if ($count == 0) {
                /* This line is not part of the element, or is not valid */
                break;
            } else {
                $line = array_shift($lines);
                $char = mb_substr($line, $count - 1, 1);

                /* Slice off the lead-in characters and put through inline parser */
                $line = mb_substr($line, $count, mb_strlen($line) - $count);
                if (count($lineBlockElement -> endChar) > 0) {
                    /* Also need to cut off end letters, such as in == Heading == */
                    $line = rtrim($line);
                    $endcount = self::countChar($lineBlockElement -> endChar, strrev($line), $lineBlockElement -> limit);
                    $line = mb_substr($line, 0, mb_strlen($line) - $endcount);
                }
                $result = $this -> parseInline($line);
                $list[] = array('depth' => $count, 'item' => $result['parsed'], 'char' => $char);
            }
        }

        if ($lineBlockElement -> nestTags) {
            /* Hierachy-ify nestable lists */
            $list = self::makeList($list);
        }
        $parsed = self::$backend -> renderLineBlock($token, $list);

        return array('parsed' => $parsed, 'remainder' => "\n". implode("\n", $lines));
    }

    /**
     * Special handling for tables, uniquely containing both per-line and recursively parsed elements
     *
     * @param string $text Text to parse
     * @return multitype:string parsed and remaining text
     */
    private function parseTable($text)
    {
        $parsed = '';
        $buffer = '';

        $lines = explode("\n", $text);
        $table['properties'] = array_shift($lines); /* get style="..." */
        $table['row'] = array();

        while (count($lines) > 0) {
            $line = array_shift($lines);
            if (trim($line) == implode(self::$tableStart -> endTag)) {
                /* End of table found */
                break;
            }

            $hit = false;
            foreach (self::$tableBlock as $token => $block) {
                /* Looking for matching per-line elements */
                if (!$hit && mb_strlen($block -> lineStart) != 0 && $block -> lineStart == mb_substr($line, 0, mb_strlen($block -> lineStart))) {
                    $hit = true;
                    break;
                }
            }

            if ($hit) {
                /* Cut found token off start of line */
                $line = mb_substr($line, mb_strlen($block -> lineStart), mb_strlen($line) - mb_strlen($block -> lineStart));

                if ($token == 'td' || $token == 'th') {
                    if (!isset($tmpRow)) {
                        /* Been given a cell before a row. Make a row first */
                        $tmpRow = array('properties' => '', 'col' => array());
                    }

                    /* Clobber the remaining text together and throw it to the cell parser */
                    array_unshift($lines, $line);
                    $result = $this -> parseTableCells($token, implode("\n", $lines), $tmpRow['col']);
                    $lines = explode("\n", $result['remainder']);
                    $tmpRow['col'] = $result['col'];
                } elseif ($token == 'tr') {
                    if (isset($tmpRow)) {
                        /* Append existing row to table (if one exists) */
                        $table['row'][] = $tmpRow;
                    }
                    /* Clearing current row and set properties */
                    $tmpRow = array('properties' => $line, 'col' => array());
                    $tmpRow['properties'] = $line;
                }
            }
        }

        if (isset($tmpRow)) {
            /* Tack on the last row */
            $table['row'][] = $tmpRow;
        }

        $parsed = self::$backend -> renderTable($table);
        return array('parsed' => $parsed, 'remainder' => "\n". implode("\n", $lines));
    }

    /**
     * Retrieve columns started in this line of text
     *
     * @param string $token Type of cells we are looking at (th or td)
     * @param string $text Text to parse
     * @param string $colsSoFar Columns which have already been found in this row
     * @return multitype:string parsed and remaining text
     */
    private function parseTableCells($token, $text, $colsSoFar)
    {
        $tableElement = self::$tableBlock[$token];
        $textChars = self::explode_string($text);
        $len = count($textChars);
        

        $tmpCol = array('arg' => array(), 'content' => '', 'token' => $token);
        $argCount = 0;
        $buffer = '';

        /* Loop through each character */
        for ($i = 0; $i < $len; $i++) {
            $hit = false;
            /* We basically detect the start of any inline/lineblock/table elements and, knowing that the inline parser knows how to handle them, throw then wayward */
            $c = $textChars[$i];
            if (isset(self::$inlineLookup[$c])) {
                /* There are inline elements which start with this character. Check each one,.. */
                foreach (self::$inlineLookup[$c] as $key => $child) {
                    if (!$hit && self::tag_is_at($child -> startTag, $textChars, $i)) {
                        //mb_strlen($child -> startTag) != 0 && $child -> startTag == mb_substr($text, $i, mb_strlen($child -> startTag))) {
                        $hit = true;
                    }
                }
            }
            if ($c == "\n") {
                if (self::tag_is_at(self::$tableStart -> startTag, $textChars, $i + 1)) {
                    //self::$tableStart -> startTag == mb_substr($text, $i + 1, mb_strlen(self::$tableStart -> startTag))) {
                    /* Table is coming up */
                    $hit = true;
                } else {
                    /* LineBlocks like lists and headings*/
                    $next = mb_substr($text, $i + 1, 1);
                    foreach (self::$lineBlock as $key => $block) {
                        foreach ($block -> startChar as $char) {
                            if (!$hit && $next == $char) {
                                $hit = true;
                                break 2;
                            }
                        }
                    }
                }
            }
                
            if ($hit) {
                /* Parse whatever it is and return here */
                $start = $i;
                $remainder = mb_substr($text, $start, $len - $start);
                $result = $this -> parseInline($remainder, 'td');
                $buffer .= $result['parsed'];
                $text = $result['remainder'];
                $textChars = self::explode_string($text);
                $len = count($textChars);
                $i = -1;
            }
                
            if (!$hit && $tableElement -> inlinesep == mb_substr($text, $i, mb_strlen($tableElement -> inlinesep))) {
                /* Got column separator, so this column is now finished */
                $tmpCol['content'] = $buffer;
                $colsSoFar[] = $tmpCol;

                /* Reset for the next */
                $tmpCol = array('arg' => array(), 'content' => '', 'token' => $token);
                $buffer = '';
                $hit = true;
                $i += mb_strlen($tableElement -> inlinesep) - 1;
                $argCount = 0;
            }
                
            if (!$hit && $argCount < ($tableElement -> limit) && $tableElement -> argsep == mb_substr($text, $i, mb_strlen($tableElement -> argsep))) {
                /* Got argument separator. Shift off the last argument */
                $tmpCol['arg'][] = $buffer;
                $buffer = '';
                $hit = true;
                $i += mb_strlen($tableElement -> argsep) - 1;
                $argCount++;
            }
                
            if (!$hit) {
                $c = mb_substr($text, $i, 1);
                if ($c == "\n") {
                    /* Checking that the next line isn't starting a different element of the table */
                    foreach (self::$tableBlock as $key => $block) {
                        if ($block -> lineStart == mb_substr($text, $i + 1, mb_strlen($block -> lineStart))) {
                            /* Next line is more table syntax. bail otu and let something else handle it */
                            break 2;
                        }
                    }
                }
                $buffer .= $c;
            }
        }

        /* Put remaining buffers in the right place */
        $tmpCol['content'] = $buffer;
        $colsSoFar[] = $tmpCol;
        $start = $i + 1;
        $remainder = mb_substr($text, $start, $len - $start);

        return array('col' => $colsSoFar, 'remainder' => $remainder);
    }

    /**
     * Count the number of times a character occurs at the start of a string
     *
     * @param string $char character to check
     * @param string $text String to search
     * @return number The number of times this character repeats at the start of the string
     */
    private static function countChar($chars, $text, $max = 0)
    {
        for ($i = 0; $i < mb_strlen($text) && ($max == 0 || $i <= $max); $i++) {
            $c = mb_substr($text, $i, 1);
            /* See if this char is a valid start */
            $match = false;
            foreach ($chars as $char) {
                $match = $match || ($char == $c);
            }
                
            if (!$match) {
                return $i;
            }
        }

        if (!($max == 0 || $i <= $max)) {
            /* max was reached */
            return $max;
        }

        /* Otherwise looks like the entire string is just this character repeated.. */
        return mb_strlen($text);
    }

    /**
     * Create a list from what we found in parseLineBlock(), returning all elements.
     */
    private static function makeList($lines)
    {
        $list = self::findChildren($lines, 0, -1);
        return $list['child'];
    }

    /**
     * Recursively nests list elements inside eachother, forming a hierachy to traverse when rendering
     */
    private static function findChildren($lines, $depth, $minKey)
    {
        $children   = array();
        $not        = array();

        foreach ($lines as $key => $line) {
            /* Loop through for candidates */
            if ($key > $minKey) {
                if ($line['depth'] > $depth) {
                    $children[$key] = $line;
                    unset($lines[$key]);
                } elseif ($line['depth'] <= $depth) {
                    break;
                }
            }
        }

        /* For each child, list its children */
        foreach ($children as $key => $child) {
            if (isset($children[$key])) {
                $result = self::findChildren($children, $child['depth'], $key);
                $children[$key]['child'] = $result['child'];

                /* We know that all of this list's children are NOT children of this item (directly), so remove them from our records. */
                foreach ($result['child'] as $notkey => $notchild) {
                    unset($children[$notkey]);
                    $not[$notkey] = true;
                }

                /* And same for non-direct children reported above */
                foreach ($result['not'] as $notkey => $foo) {
                    unset($children[$notkey]);
                    $not[$notkey] = true;
                }
            }
        }

        return array('child' => $children, 'not' => $not);
    }
}
