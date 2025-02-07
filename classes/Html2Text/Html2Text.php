<?php
// This file is part of the Accredible Certificate module for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_accredible\Html2Text;
defined('MOODLE_INTERNAL') || die();

/**
 * Class to convert HTML into plain text.
 *
 * @package    mod_accredible
 * @subpackage accredible
 * @copyright  Accredible <dev@accredible.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class Html2Text {
    /**
     * Tries to convert the given HTML into a plain text format - best suited for
     * e-mail display, etc.
     *
     * <p>In particular, it tries to maintain the following features:
     * <ul>
     *   <li>Links are maintained, with the 'href' copied over
     *   <li>Information in the &lt;head&gt; is lost
     * </ul>
     *
     * @param string $html the input HTML
     * @param bool $ignoreerror Ignore xml parsing errors
     * @return string the HTML converted, as best as possible, to text
     * @throws Html2TextException if the HTML could not be loaded as a {@see DOMDocument}
     */
    public static function convert($html, $ignoreerror = false) {
        // Replace &nbsp; with spaces.
        $html = str_replace("&nbsp;", " ", $html);
        $html = str_replace("\xc2\xa0", " ", $html);

        $isofficedocument = static::is_office_document($html);

        if ($isofficedocument) {
            // Remove office namespace.
            $html = str_replace(array("<o:p>", "</o:p>"), "", $html);
        }

        $html = static::fix_new_lines($html);
        if (mb_detect_encoding($html, "UTF-8", true)) {
            $html = mb_convert_encoding($html, "HTML-ENTITIES", "UTF-8");
        }

        $doc = static::get_document($html, $ignoreerror);

        $output = static::iterate_over_node($doc, null, false, $isofficedocument);

        // Remove leading and trailing spaces on each line.
        $output = preg_replace("/[ \t]*\n[ \t]*/im", "\n", $output);
        $output = preg_replace("/ *\t */im", "\t", $output);

        // Unarmor pre blocks.
        $output = str_replace("\r", "\n", $output);

        // Remove unnecessary empty lines.
        $output = preg_replace("/\n\n\n*/im", "\n\n", $output);

        // Remove leading and trailing whitespace.
        $output = trim($output);

        return $output;
    }

    /**
     * Unify newlines; in particular, \r\n becomes \n, and
     * then \r becomes \n. This means that all newlines (Unix, Windows, Mac)
     * all become \ns.
     *
     * @param string $text text with any number of \r, \r\n and \n combinations
     * @return string the fixed text
     */
    public static function fix_new_lines($text) {
        // Replace \r\n to \n.
        $text = str_replace("\r\n", "\n", $text);
        // Remove \rs.
        $text = str_replace("\r", "\n", $text);

        return $text;
    }

    /**
     * Parse HTML into a DOMDocument
     *
     * @param string $html the input HTML
     * @param bool $ignoreerror Ignore xml parsing errors
     * @return DOMDocument the parsed document tree
     */
    public static function get_document($html, $ignoreerror = false) {

        $doc = new \DOMDocument();

        $html = trim($html);

        if (!$html) {
            // DOMDocument doesn't support empty value and throws an error.
            // Return empty document instead.
            return $doc;
        }

        if ($html[0] !== '<') {
            // If HTML does not begin with a tag, we put a body tag around it.
            // If we do not do this, PHP will insert a paragraph tag around
            // the first block of text for some reason which can mess up
            // the newlines. See pre.html test for an example.
            $html = '<body>' . $html . '</body>';
        }

        if ($ignoreerror) {
            $doc->strictErrorChecking = false;
            $doc->recover = true;
            $doc->xmlStandalone = true;
            $oldinternalerrors = libxml_use_internal_errors(true);
            $loadresult = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
            libxml_use_internal_errors($oldinternalerrors);
        } else {
            $loadresult = $doc->loadHTML($html);
        }

        if (!$loadresult) {
            throw new Html2TextException("Could not load HTML - badly formed?", $html);
        }

        return $doc;
    }

    /**
     * Can we guess that this HTML is generated by Microsoft Office?
     *
     * @param string $html the input HTML
     * @return bool true if generated by Microsoft Office
     */
    public static function is_office_document($html) {
        return strpos($html, "urn:schemas-microsoft-com:office") !== false;
    }

    /**
     * Check if string is empty or only contains whitespaces
     *
     * @param string $text the input HTML
     * @return bool true if whitespaces
     */
    public static function is_whitespace($text) {
        return strlen(trim($text, "\n\r\t ")) === 0;
    }

    /**
     * Get the next child element of the document
     *
     * @param DOMDocument|DOMElement|DOMText $node the input HTML
     * @return string the name of the next node.
     */
    public static function next_child_name($node) {
        // Get the next child.
        $nextnode = $node->nextSibling;
        while ($nextnode != null) {
            if ($nextnode instanceof \DOMText) {
                if (!static::is_whitespace($nextnode->wholeText)) {
                    break;
                }
            }
            if ($nextnode instanceof \DOMElement) {
                break;
            }
            $nextnode = $nextnode->nextSibling;
        }
        $nextname = null;
        if (($nextnode instanceof \DOMElement || $nextnode instanceof \DOMText) && $nextnode != null) {
            $nextname = strtolower($nextnode->nodeName);
        }

        return $nextname;
    }

    /**
     * Iterate over the DOMDocument nodes and all their childs
     * to parse the html into plain text
     *
     * @param DOMDocument|DOMElement|DOMText|DOMDocumentType|DOMProcessingInstruction $node the DOM node
     * @param string $prevname
     * @param bool $inpre
     * @param bool $isofficedocument
     * @return string the html parsed to plain text
     */
    public static function iterate_over_node($node, $prevname = null, $inpre = false, $isofficedocument = false) {

        if ($node instanceof \DOMText) {
            // Replace whitespace characters with a space (equivilant to \s).
            if ($inpre) {
                $text = "\n" . trim($node->wholeText, "\n\r\t ") . "\n";
                // Remove trailing whitespace only.
                $text = preg_replace("/[ \t]*\n/im", "\n", $text);
                // Armor newlines with \r.
                return str_replace("\n", "\r", $text);
            } else {
                $text = preg_replace("/[\\t\\n\\f\\r ]+/im", " ", $node->wholeText);
                if (!static::is_whitespace($text) && ($prevname == 'p' || $prevname == 'div')) {
                    return "\n" . $text;
                }
                return $text;
            }
        }
        if ($node instanceof \DOMDocumentType) {
            // Ignore.
            return "";
        }
        if ($node instanceof \DOMProcessingInstruction) {
            // Ignore.
            return "";
        }

        $name = strtolower($node->nodeName);
        $nextname = static::next_child_name($node);

        // Start whitespace.
        switch ($name) {
            case "hr":
                $prefix = '';
                if ($prevname != null) {
                    $prefix = "\n";
                }
                return $prefix . "---------------------------------------------------------------\n";

            case "style":
            case "head":
            case "title":
            case "meta":
            case "script":
                // Ignore these tags.
                return "";

            case "h1":
            case "h2":
            case "h3":
            case "h4":
            case "h5":
            case "h6":
            case "ol":
            case "ul":
                // Add two newlines, second line is added below.
                $output = "\n";
                break;

            case "td":
            case "th":
                // Add tab char to separate table fields.
                $output = "\t";
                break;

            case "p":
                // Microsoft exchange emails often include HTML which, when passed through
                // html2text, results in lots of double line returns everywhere.
                //
                // To fix this, for any p element with a className of `MsoNormal` (the standard
                // classname in any Microsoft export or outlook for a paragraph that behaves
                // like a line return) we skip the first line returns and set the name to br.
                if ($isofficedocument && $node->getAttribute('class') == 'MsoNormal') {
                    $output = "";
                    $name = 'br';
                    break;
                }
                // Add two lines.
                $output = "\n\n";
                break;

            case "pre":
            case "tr":
            case "div":
                // Add one line.
                $output = "\n";
                break;

            case "li":
                $output = "- ";
                break;

            default:
                // Print out contents of unknown tags.
                $output = "";
                break;
        }

        if (isset($node->childNodes)) {

            $n = $node->childNodes->item(0);
            $previoussiblingname = null;

            while ($n != null) {

                $text = static::iterate_over_node($n, $previoussiblingname, $inpre || $name == 'pre', $isofficedocument);

                // Pass current node name to next child, as previousSibling does not appear to get populated.
                if (!$n instanceof \DOMDocumentType
                    && !$n instanceof \DOMProcessingInstruction
                    && !($n instanceof \DOMText && static::is_whitespace($text))) {
                    $previoussiblingname = strtolower($n->nodeName);
                }

                $node->removeChild($n);
                $n = $node->childNodes->item(0);

                // Suppress last br tag inside a node list.
                if ($n != null || $previoussiblingname != 'br') {
                    $output .= $text;
                }
            }
        }

        // End whitespace.
        switch ($name) {
            case "h1":
            case "h2":
            case "h3":
            case "h4":
            case "h5":
            case "h6":
                $output .= "\n";
                break;

            case "p":
                $output .= "\n\n";
                break;

            case "pre":
            case "br":
                $output .= "\n";
                break;

            case "div":
                break;

            case "a":
                // Links are returned in [text](link) format.
                $href = $node->getAttribute("href");

                $output = trim($output);

                // Remove double [[ ]] s from linking images.
                if (substr($output, 0, 1) == "[" && substr($output, -1) == "]") {
                    $output = substr($output, 1, strlen($output) - 2);

                    // For linking images, the title of the <a> overrides the title of the <img>.
                    if ($node->getAttribute("title")) {
                        $output = $node->getAttribute("title");
                    }
                }

                // If there is no link text, but a title attr.
                if (!$output && $node->getAttribute("title")) {
                    $output = $node->getAttribute("title");
                }

                if ($href == null) {
                    // It doesn't link anywhere.
                    if ($node->getAttribute("name") != null) {
                        $output = "[$output]";
                    }
                } else {
                    if ($href == $output || $href == "mailto:$output" || $href == "http://$output" || $href == "https://$output") {
                        // Link to the same address: just use link.
                        $output;
                    } else {
                        // Replace it.
                        if ($output) {
                            $output = "[$output]($href)";
                        } else {
                            // Empty string.
                            $output = $href;
                        }
                    }
                }

                // Does the next node require additional whitespace?
                switch ($nextname) {
                    case "h1":
                    case "h2":
                    case "h3":
                    case "h4":
                    case "h5":
                    case "h6":
                        $output .= "\n";
                        break;
                }
                break;

            case "img":
                if ($node->getAttribute("title")) {
                    $output = "[" . $node->getAttribute("title") . "]";
                } else if ($node->getAttribute("alt")) {
                    $output = "[" . $node->getAttribute("alt") . "]";
                } else {
                    $output = "";
                }
                break;

            case "li":
                $output .= "\n";
                break;

            default:
                // Do nothing.
        }

        return $output;
    }
}
