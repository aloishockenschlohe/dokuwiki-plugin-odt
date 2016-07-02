<?php
/**
 * docHandler: Abstract class defining the interface for classes
 * which create the ODT document file/zip archive.
 *
 * Most code was taken from renderer.php.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Aurelien Bompard <aurelien@bompard.org>
 * @author LarsDW223
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once DOKU_PLUGIN . 'odt/ODT/ODTUnits.php';

/**
 * The docHandler interface
 */
abstract class docHandler
{
    public $trace_dump = NULL;
    protected $registrations = array();
    protected $internalRegs = array('heading1' => array('element' => 'h1', 'attributes' => NULL),
                                    'heading2' => array('element' => 'h2', 'attributes' => NULL),
                                    'heading3' => array('element' => 'h3', 'attributes' => NULL),
                                    'heading4' => array('element' => 'h4', 'attributes' => NULL),
                                    'heading5' => array('element' => 'h5', 'attributes' => NULL),
                                    'horizontal line' => array('element' => 'hr', 'attributes' => NULL),
                                    'body' => array('element' => 'p', 'attributes' => NULL),
                                    'emphasis' => array('element' => 'em', 'attributes' => NULL, 'compare' => true),
                                    'strong' => array('element' => 'strong', 'attributes' => NULL, 'compare' => true),
                                    'underline' => array('element' => 'u', 'attributes' => NULL, 'compare' => true),
                                    'monospace' => array('element' => 'code', 'attributes' => NULL),
                                    'del' => array('element' => 'del', 'attributes' => NULL, 'compare' => true),
                                    'preformatted' => array('element' => 'pre', 'attributes' => NULL),
                                    'source code' => array('element' => 'pre', 'attributes' => 'class="code"'),
                                    'source file' => array('element' => 'pre', 'attributes' => 'class="file"'),
                                    'quotation1' => array('element' => 'quotation1', 'attributes' => NULL),
                                    'quotation2' => array('element' => 'quotation1', 'attributes' => NULL),
                                    'quotation3' => array('element' => 'quotation1', 'attributes' => NULL),
                                    'quotation4' => array('element' => 'quotation1', 'attributes' => NULL),
                                    'quotation5' => array('element' => 'quotation1', 'attributes' => NULL),
                                   );
    protected $table_styles = array('table' => array('element' => 'table', 'attributes' => NULL),
                                    'table header' => array('element' => 'th', 'attributes' => NULL),
                                    'table cell' => array('element' => 'td', 'attributes' => NULL)
                                   );
    protected $link_styles = array(
                                   'internet link' => array('element' => 'a',
                                                            'attributes' => NULL,
                                                            'pseudo-class' => 'link'),
                                   'visited internet link' => array('element' => 'a',
                                                                    'attributes' => NULL,
                                                                    'pseudo-class' => 'visited'),
                                   'local link' => array('element' => 'a', 
                                                         'attributes' => 'class="wikilink1"',
                                                         'pseudo-class' => NULL),
                                   'visited local link' => array('element' => 'a',
                                                                 'attributes' => 'class="wikilink1"',
                                                                 'pseudo-class' => NULL),
                                  );

    /**
     * Build ODT document.
     *
     * @param string      $doc
     * @param string      $meta
     * @param string      $userfields
     * @return mixed
     */
    abstract public function build(ODTInternalParams $params, $meta=null, $userfields=null, $pagestyles=null);

    protected function setListStyleImage (ODTInternalParams $params, $style, $level, $file) {
        $odt_file = $params->document->addFileAsPicture($file);

        if ( $odt_file != NULL ) {
            $style->setPropertyForLevel($level, 'list-level-style', 'image');
            $style->setPropertyForLevel($level, 'href', $odt_file);
            $style->setPropertyForLevel($level, 'type', 'simple');
            $style->setPropertyForLevel($level, 'show', 'embed');
            $style->setPropertyForLevel($level, 'actuate', 'onLoad');
            $style->setPropertyForLevel($level, 'vertical-pos', 'middle');
            $style->setPropertyForLevel($level, 'vertical-rel', 'line');

            list($width, $height) = ODTUtility::getImageSize($file);
            if (empty($width) || empty($height)) {
                $width = '0.5';
                $height = $width;
            }
            $style->setPropertyForLevel($level, 'width', $width.'cm');
            $style->setPropertyForLevel($level, 'height', $height.'cm');

            // ??? Wie berechnen...
            $text_indent = ODTUnits::getDigits($style->getPropertyFromLevel($level, 'text-indent'));
            $margin_left = ODTUnits::getDigits($style->getPropertyFromLevel($level, 'margin_left'));
            $tab_stop_position =
                ODTUnits::getDigits($style->getPropertyFromLevel($level, 'list-tab-stop-position'));
            $minimum = $margin_left + $text_indent + $width;
            if ($minimum > $tab_stop_position) {
                $inc = abs($text_indent);
                if ($inc == 0 ) {
                    $inc = 0.5;
                }
                while ($minimum > $tab_stop_position) {
                    $tab_stop_position += $inc;
                }
            }
            $style->setPropertyForLevel($level, 'list-tab-stop-position', $tab_stop_position.'cm');
        }
    }

    protected function importOrderedListStyles(ODTInternalParams $params, cssdocument $htmlStack) {
        $name = $params->styleset->getStyleName('numbering');
        $style = $params->styleset->getStyle($name);
        if ($style == NULL ) {
            return;
        }

        // Reset stack to saved root so next importStyle
        // will have the same conditions
        $htmlStack->restoreToRoot ();

        for ($level = 1 ; $level < 11 ; $level++) {
            // Push our element to import on the stack
            $htmlStack->open('ol');
            $toMatch = $htmlStack->getCurrentElement();

            $properties = array();                
            $params->import->getPropertiesForElement($properties, $toMatch, $params->units);

            // Adjust values for ODT
            ODTUtility::adjustValuesForODT ($properties, $params->units);

            if ($properties ['list-style-type'] !== NULL) {
                $prefix = NULL;
                $suffix = '.';
                $numbering = trim($properties ['list-style-type'],'"');
                switch ($numbering) {
                    case 'decimal':
                        $numbering = '1';
                        break;
                    case 'decimal-leading-zero':
                        $numbering = '1';
                        $prefix = '0';
                        break;
                    case 'lower-alpha':
                    case 'lower-latin':
                        $numbering = 'a';
                        break;
                    case 'lower-roman':
                        $numbering = 'i';
                        break;
                    case 'none':
                        $numbering = '';
                        $suffix = '';
                        break;
                    case 'upper-alpha':
                    case 'upper-latin':
                        $numbering = 'A';
                        break;
                    case 'upper-roman':
                        $numbering = 'I';
                        break;
                }
                $style->setPropertyForLevel($level, 'num-format', $numbering);
                if ($prefix != NULL ) {
                    $style->setPropertyForLevel($level, 'num-prefix', $prefix);
                }
                $style->setPropertyForLevel($level, 'num-suffix', $suffix);

                if ($properties ['padding-left'] != NULL) {
                    $style->setPropertyForLevel($level, 'text-min-label-distance', $properties ['padding-left']);
                }
            }
            if ($properties ['list-style-image'] !== NULL && $properties ['list-style-image'] != 'none') {
                // It is assumed that the CSS already contains absolute path values only!
                // (see replaceURLPrefixes)
                $file = $properties ['list-style-image'];
                /*$file = substr($file, 4);
                $file = trim($file, "()'");
                if ($media_path [strlen($media_path)-1] != '/') {
                    $media_path .= '/';
                }
                $file = $media_path.$file;*/
                
                $this->setListStyleImage ($params, $style, $level, $file);
            }

            // Import properties for list paragraph style once.
            // Margins MUST be ignored!
            // See extra handling in importUnorderedListStyles() for
            // paragraph styles 'list first paragraph' and 'list last paragraph'
            if ($level == 1) {
                $disabled = array();
                $disabled ['margin-left'] = 1;
                $disabled ['margin-right'] = 1;
                $disabled ['margin-top'] = 1;
                $disabled ['margin-bottom'] = 1;

                $name = $params->styleset->getStyleName('numbering content');
                $paragraphStyle = $params->styleset->getStyle($name);
                $paragraphStyle->importProperties($properties, $disabled);
            }
        }

        // Reset stack to saved root so next importStyle
        // will have the same conditions
        $htmlStack->restoreToRoot ();
    }

    protected function importUnorderedListStyles(ODTInternalParams $params, cssdocument $htmlStack) {
        $name = $params->styleset->getStyleName('list');
        $style = $params->styleset->getStyle($name);
        if ($style == NULL ) {
            return;
        }

        // Workaround for ODT format, see end of loop
        $name = $params->styleset->getStyleName('list first paragraph');
        $firstStyle = $params->styleset->getStyle($name);
        $name = $params->styleset->getStyleName('list last paragraph');
        $lastStyle = $params->styleset->getStyle($name);

        // Reset stack to saved root so next importStyle
        // will have the same conditions
        $htmlStack->restoreToRoot ();

        for ($level = 1 ; $level < 11 ; $level++) {
            // Push our element to import on the stack
            $htmlStack->open('ul');
            $toMatch = $htmlStack->getCurrentElement();

            $properties = array();                
            $params->import->getPropertiesForElement($properties, $toMatch, $params->units);

            // Adjust values for ODT
            ODTUtility::adjustValuesForODT ($properties, $params->units);
            
            if ($properties ['list-style-type'] !== NULL) {
                switch ($properties ['list-style-type']) {
                    case 'disc':
                    case 'bullet':
                        $sign = '•';
                        break;
                    case 'circle':
                        $sign = '∘';
                        break;
                    case 'square':
                        $sign = '▪';
                        break;
                    case 'none':
                        $sign = ' ';
                        break;
                    case 'blackcircle':
                        $sign = '●';
                        break;
                    case 'heavycheckmark':
                        $sign = '✔';
                        break;
                    case 'ballotx':
                        $sign = '✗';
                        break;
                    case 'heavyrightarrow':
                        $sign = '➔';
                        break;
                    case 'lightedrightarrow':
                        $sign = '➢';
                        break;
                    default:
                        $sign = trim($properties ['list-style-type'],'"');
                        break;
                }
                $style->setPropertyForLevel($level, 'text-bullet-char', $sign);
            }
            if ($properties ['list-style-image'] !== NULL && $properties ['list-style-image'] != 'none') {
                // It is assumed that the CSS already contains absolute path values only!
                // (see replaceURLPrefixes)
                $file = $properties ['list-style-image'];
                /*$file = substr($file, 4);
                $file = trim($file, "()'");
                if ($media_path [strlen($media_path)-1] != '/') {
                    $media_path .= '/';
                }
                $file = $media_path.$file;*/
                
                $this->setListStyleImage ($params, $style, $level, $file);
            }

            // Workaround for ODT format:
            // We can not set margins on the list itself.
            // So we use extra paragraph styles for the first and last
            // list items to set a margin.
            if ($level == 1 &&
                ($properties ['margin-left'] != NULL ||
                 $properties ['margin-right'] != NULL ||
                 $properties ['margin-top'] != NULL ||
                 $properties ['margin-bottom'] != NULL)) {
                $set = array ();
                $set ['margin-left'] = $properties ['margin-left'];
                $set ['margin-right'] = $properties ['margin-right'];
                $set ['margin-top'] = $properties ['margin-top'];
                $set ['margin-bottom'] = '0pt';
                $firstStyle->importProperties($set);
                $set ['margin-bottom'] = $properties ['margin-bottom'];
                $set ['margin-top'] = '0pt';
                $lastStyle->importProperties($set);
            }

            // Import properties for list paragraph style once.
            // Margins MUST be ignored! See extra handling above.
            if ($level == 1) {
                $disabled = array();
                $disabled ['margin-left'] = 1;
                $disabled ['margin-right'] = 1;
                $disabled ['margin-top'] = 1;
                $disabled ['margin-bottom'] = 1;

                $name = $params->styleset->getStyleName('list content');
                $paragraphStyle = $params->styleset->getStyle($name);
                $paragraphStyle->importProperties($properties, $disabled);
            }
        }

        // Reset stack to saved root so next importStyle
        // will have the same conditions
        $htmlStack->restoreToRoot ();
    }

    protected function importTableStyles(ODTInternalParams $params, cssdocument $htmlStack) {
        foreach ($this->table_styles as $style_type => $elementParams) {
            $name = $params->styleset->getStyleName($style_type);
            $style = $params->styleset->getStyle($name);
            if ( $style != NULL ) {
                $element = $elementParams ['element'];
                $attributes = $elementParams ['attributes'];

                // Push our element to import on the stack
                $htmlStack->open($element, $attributes);
                $toMatch = $htmlStack->getCurrentElement();
                
                //$element_to_check = 'td';

                $properties = array();                
                $params->import->getPropertiesForElement($properties, $toMatch, $params->units);
                if (count($properties) == 0) {
                    // Nothing found. Back to top, DO NOT change existing style!

                    //if ($this->trace_dump != NULL && $element == $element_to_check) {
                    //    $this->trace_dump .= 'Nothing found for '.$element_to_check."!\n";
                    //}

                    continue;
                }

                // We have found something.
                // First clear the existing layout properties of the style.
                $style->clearLayoutProperties();

                //if ($this->trace_dump != NULL && $element == $element_to_check) {
                //    $this->trace_dump .= 'Checking '.$element_to_check.'['.$attributes.']';
                //    $this->trace_dump .= 'BEFORE:'."\n";
                //    foreach ($properties as $key => $value) {
                //        $this->trace_dump .= $key.'='.$value."\n";
                //    }
                //    $this->trace_dump .= '---------------------------------------'."\n";
                //}

                // Adjust values for ODT
                ODTUtility::adjustValuesForODT ($properties, $params->units);

                /*if ($this->trace_dump != NULL && $element == $element_to_check) {
                    $this->trace_dump .= 'AFTER:'."\n";
                    foreach ($properties as $key => $value) {
                        $this->trace_dump .= $key.'='.$value."\n";
                    }
                    $this->trace_dump .= '---------------------------------------'."\n";
                }*/

                // If the style imported is a table adjust some properties
                if ($style->getFamily() == 'table') {
                    // Move 'width' to 'rel-width' if it is relative
                    $width = $properties ['width'];
                    if ($width != NULL) {
                        if ($properties ['align'] == NULL) {
                            // If width is set but align not, changing the width
                            // will not work. So we set it here if not done by the user.
                            $properties ['align'] = 'center';
                        }
                    }
                    if ($width [strlen($width)-1] == '%') {
                        $properties ['rel-width'] = $width;
                        unset ($properties ['width']);
                    }

                    // Convert property 'border-model' to ODT
                    if ( !empty ($properties ['border-collapse']) ) {
                        $properties ['border-model'] = $properties ['border-collapse'];
                        unset ($properties ['border-collapse']);
                        if ( $properties ['border-model'] == 'collapse' ) {
                            $properties ['border-model'] = 'collapsing';
                        } else {
                            $properties ['border-model'] = 'separating';
                        }
                    }
                }

                // Inherit properties for table header paragraph style from
                // the properties of the 'th' element
                if ($element == 'th') {
                    $name = $params->styleset->getStyleName('table heading');
                    $paragraphStyle = $params->styleset->getStyle($name);

                    // Do not set borders on our paragraph styles in the table.
                    // Otherwise we will have double borders. Around the cell and
                    // around the text in the cell!
                    $disabled = array();
                    $disabled ['border']        = 1;
                    $disabled ['border-top']    = 1;
                    $disabled ['border-right']  = 1;
                    $disabled ['border-bottom'] = 1;
                    $disabled ['border-left']   = 1;
                    // Do not set background/background-color
                    $disabled ['background-color'] = 1;

                    $paragraphStyle->clearLayoutProperties();
                    $paragraphStyle->importProperties($properties, $disabled);
                }
                // Inherit properties for table content paragraph style from
                // the properties of the 'td' element
                if ($element == 'td') {
                    $name = $params->styleset->getStyleName('table content');
                    $paragraphStyle = $params->styleset->getStyle($name);

                    // Do not set borders on our paragraph styles in the table.
                    // Otherwise we will have double borders. Around the cell and
                    // around the text in the cell!
                    $disabled = array();
                    $disabled ['border']        = 1;
                    $disabled ['border-top']    = 1;
                    $disabled ['border-right']  = 1;
                    $disabled ['border-bottom'] = 1;
                    $disabled ['border-left']   = 1;
                    // Do not set background/background-color
                    $disabled ['background-color'] = 1;
                    
                    $paragraphStyle->clearLayoutProperties();
                    $paragraphStyle->importProperties($properties, $disabled);
                }
                $style->importProperties($properties, NULL);

                // Reset stack to saved root so next importStyle
                // will have the same conditions
                $htmlStack->restoreToRoot ();
            }
        }
    }

    protected function importLinkStyles(ODTInternalParams $params, cssdocument $htmlStack) {
        foreach ($this->link_styles as $style_type => $elementParams) {
            $name = $params->styleset->getStyleName($style_type);
            $style = $params->styleset->getStyle($name);
            if ( $name != NULL && $style != NULL ) {
                $element = $elementParams ['element'];
                $attributes = $elementParams ['attributes'];
                $pseudo_class = $elementParams ['pseudo-class'];

                // Push our element to import on the stack
                $htmlStack->open($element, $attributes, $pseudo_class, NULL);
                $toMatch = $htmlStack->getCurrentElement();

                $properties = array();                
                $params->import->getPropertiesForElement($properties, $toMatch, $params->units);
                if (count($properties) == 0) {
                    // Nothing found. Back to top, DO NOT change existing style!
                    continue;
                }

                // We have found something.
                // First clear the existing layout properties of the style.
                $style->clearLayoutProperties();

                // Adjust values for ODT
                ODTUtility::adjustValuesForODT ($properties, $params->units);

                $style->importProperties($properties, NULL);

                // Reset stack to saved root so next importStyle
                // will have the same conditions
                $htmlStack->restoreToRoot ();
            }
        }
    }

    protected function importStyle(ODTInternalParams $params, cssdocument $htmlStack, $style_type, $element, $attributes=NULL, array $plain=NULL) {
        $name = $params->styleset->getStyleName($style_type);
        $style = $params->styleset->getStyle($name);
        if ( $style != NULL ) {
            // Push our element to import on the stack
            $htmlStack->open($element, $attributes);
            $toMatch = $htmlStack->getCurrentElement();
            
            $element_to_check = 'p123';
            
            $properties = array();
            $params->import->getPropertiesForElement($properties, $toMatch, $params->units);
            if (count($properties) == 0) {
                // Nothing found. Return, DO NOT change existing style!

                if ($this->trace_dump != NULL && $element == $element_to_check) {
                    $this->trace_dump .= 'Nothing found for '.$element_to_check."!\n";
                }

                return;
            }
            if ($plain != NULL)
            {
                $diff = array_diff ($properties, $plain);
                if (count($diff) == 0) {
                    // Workaround for some elements, e.g. 'em' and 'del':
                    // They may have default values from the browser only.
                    // In that case do not import the style otherwise
                    // 'em' and 'del' will look like plain text.
                    return;
                }
            }

            // We have found something.
            // First clear the existing layout properties of the style.
            $style->clearLayoutProperties();

            if ($this->trace_dump != NULL && $element == $element_to_check) {
                $this->trace_dump .= 'Checking '.$element_to_check.'['.$attributes.']';
                $this->trace_dump .= 'BEFORE:'."\n";
                foreach ($properties as $key => $value) {
                    $this->trace_dump .= $key.'='.$value."\n";
                }
                $this->trace_dump .= '---------------------------------------'."\n";
            }

            // Adjust values for ODT
            ODTUtility::adjustValuesForODT ($properties, $params->units);

            if ($this->trace_dump != NULL && $element == $element_to_check) {
                $this->trace_dump .= 'AFTER:'."\n";
                foreach ($properties as $key => $value) {
                    $this->trace_dump .= $key.'='.$value."\n";
                }
                $this->trace_dump .= '---------------------------------------'."\n";
            }

            // In all paragraph styles set the ODT specific attribute join-border = false
            if ($style->getFamily() == 'paragraph') {
                $properties ['join-border'] = 'false';
            }

            $style->importProperties($properties, NULL);

            // Reset stack to saved root so next importStyle
            // will have the same conditions
            $htmlStack->restoreToRoot ();
        }
    }

    public function import_styles_from_css (ODTInternalParams $params, $media_sel=NULL) {
        if ( $params->import != NULL ) {
            $save = $params->import->getMedia ();
            $params->import->setMedia ($media_sel);
            
            // Make a copy of the stack to be sure we do not leave anything behind after import.
            $stack = clone $params->htmlStack;
            $stack->restoreToRoot ();

            $this->import_styles_from_css_internal ($params, $stack);

            $params->import->setMedia ($save);
        }
    }

    public function set_page_properties (ODTInternalParams $params, ODTPageLayoutStyle $pageStyle, $media_sel=NULL) {
        if ( $params->import != NULL ) {
            if ($media_sel != NULL ) {
                $save = $params->import->getMedia ();
                $params->import->setMedia ($media_sel);
            }

            $stack = clone $params->htmlStack;
            $stack->restoreToRoot ();

            // Set background-color of page
            // It is assumed that the last element of the "root" elements hold the backround-color.
            // For DokuWiki this is <div class="page group">, see renderer/page.php, function 'load_css()'
            $stack->restoreToRoot ();
            $properties = array();
            $params->import->getPropertiesForElement($properties, $stack->getCurrentElement(), $params->units);
            ODTUtility::adjustValuesForODT ($properties, $params->units);
            if (!empty($properties ['background-color'])) {
                if ($pageStyle != NULL) {
                    $pageStyle->setProperty('background-color', $properties ['background-color']);
                }
            }

            if ($media_sel != NULL ) {
                $params->import->setMedia ($save);
            }
        }
    }

    protected function importParagraphDefaultStyle(ODTInternalParams $params) {
        // This function MUST be called at the end of import_styles_from_css_internal
        // ==> the 'body' paragraph style must have alread been imported!

        // Get standard text style ('body')
        $styleName = $params->styleset->getStyleName('body');
        $body = $params->styleset->getStyle($styleName);

        // Copy body paragraph properties to the paragraph default styles
        // But not margins and paddings:
        // That would also influence the margin and paddings in the
        // Table of Contents or in lists
        $disabled = array();
        $disabled ['margin'] = 1;
        $disabled ['margin-top'] = 1;
        $disabled ['margin-right'] = 1;
        $disabled ['margin-bottom'] = 1;
        $disabled ['margin-left'] = 1;
        $disabled ['padding'] = 1;
        $disabled ['padding-top'] = 1;
        $disabled ['padding-right'] = 1;
        $disabled ['padding-bottom'] = 1;
        $disabled ['padding-left'] = 1;
        
        $default = $params->styleset->getDefaultStyle ('paragraph');
        if ($default != NULL && $body != NULL) {
            ODTParagraphStyle::copyLayoutProperties ($body, $default, $disabled);
        }
    }

    protected function importFootnoteStyle(ODTInternalParams $params) {
        // This function MUST be called at the end of import_styles_from_css_internal
        // ==> the 'body' paragraph style must have alread been imported!

        // Get standard text style ('body')
        $styleName = $params->styleset->getStyleName('body');
        $body = $params->styleset->getStyle($styleName);

        // Copy body paragraph properties to the footnote style
        // But not margins and paddings.
        $disabled = array();
        $disabled ['margin'] = 1;
        $disabled ['margin-top'] = 1;
        $disabled ['margin-right'] = 1;
        $disabled ['margin-bottom'] = 1;
        $disabled ['margin-left'] = 1;
        $disabled ['padding'] = 1;
        $disabled ['padding-top'] = 1;
        $disabled ['padding-right'] = 1;
        $disabled ['padding-bottom'] = 1;
        $disabled ['padding-left'] = 1;
        
        $styleName = $params->styleset->getStyleName (footnote);
        $footnote = $params->styleset->getStyle($styleName);
        if ($footnote != NULL && $body != NULL) {
            ODTParagraphStyle::copyLayoutProperties ($body, $footnote, $disabled);
        }
    }

    protected function import_styles_from_css_internal(ODTInternalParams $params, $htmlStack) {
        // Import page layout
        $name = $params->styleset->getStyleName('first page');
        $first_page = $params->styleset->getStyle($name);
        if ($first_page != NULL) {
            $this->set_page_properties ($params, $first_page, $htmlStack, NULL);
        }

        // Import styles which only require a simple import based on element name and attributes

        // Get style of plain text paragraph for comparison
        // See importStyle()
        $htmlStack->restoreToRoot ();
        $htmlStack->open('p');
        $toMatch = $htmlStack->getCurrentElement();
        $properties = array();
        $params->import->getPropertiesForElement($properties, $toMatch, $params->units);
        $htmlStack->restoreToRoot ();

        $toImport = array_merge ($this->internalRegs, $this->registrations);
        foreach ($toImport as $style => $element) {
            if ($element ['compare']) {
                $this->importStyle($params, $htmlStack,
                                   $style,
                                   $element ['element'],
                                   $element ['attributes'],
                                   $properties);
            } else {
                $this->importStyle($params, $htmlStack,
                                   $style,
                                   $element ['element'],
                                   $element ['attributes'],
                                   NULL);
            }
        }

        // Import table styles
        $this->importTableStyles($params, $htmlStack);

        // Import link styles (require extra pseudo class handling)
        $this->importLinkStyles($params, $htmlStack);

        // Import list styles and list paragraph styles
        $this->importUnorderedListStyles($params, $htmlStack);
        $this->importOrderedListStyles($params, $htmlStack);

        $this->importParagraphDefaultStyle($params);
        $this->importFootnoteStyle($params);
    }
    
    public function registerHTMLElementForCSSImport ($style_type, $element, $attributes=NULL) {
        $this->registrations [$style_type]['element'] = $element;
        $this->registrations [$style_type]['attributes'] = $attributes;
    }
}
