<?php
/**
 * ODT Plugin: Exports to ODT
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Aurelien Bompard <aurelien@bompard.org>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

require_once DOKU_PLUGIN . 'odt/helper/cssimport.php';
require_once DOKU_PLUGIN . 'odt/ODT/ODTDefaultStyles.php';

// Central class for ODT export
require_once DOKU_PLUGIN . 'odt/ODT/ODTDocument.php';

/**
 * The Page Renderer
 * 
 * @package DokuWiki\Renderer\Page
 */
class renderer_plugin_odt_page extends Doku_Renderer {
    /** @var helper_plugin_odt_cssimport */
    protected $import = null;
    /** @var helper_plugin_odt_config */
    protected $config = null;
    protected $document = null;
    /** @var string */
    protected $css;

    /**
     * Constructor. Loads helper plugins.
     */
    public function __construct() {
        // Set up empty array with known config parameters
        $this->config = plugin_load('helper', 'odt_config');

        // Create and initialize document
        $this->document = new ODTDocument();
        $this->document->initialize ();
    }

    /**
     * Set a config parameter from extern.
     */
    public function setConfigParam($name, $value) {
        $this->config->setParam($name, $value);
    }

    /**
     * Is the $string specified the name of a ODT plugin config parameter?
     *
     * @return bool Is it a config parameter?
     */
    public function isConfigParam($string) {
        return $this->config->isParam($string);
    }

    /**
     * Return version info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/plugin.info.txt');
    }

    /**
     * Returns the format produced by this renderer.
     */
    function getFormat(){
        return "odt";
    }

    /**
     * Do not make multiple instances of this class
     */
    function isSingleton(){
        return true;
    }

    public function replaceURLPrefixesCallback ($property, $value, $url) {
        if (strncmp($url, '/lib/plugins', strlen('/lib/plugins')) == 0) {
            return DOKU_INC.substr($url,1);
        }
        return $url;
    }

    /**
     * Load and imports CSS.
     */
    protected function load_css() {
        global $conf, $lang;

        /** @var helper_plugin_odt_dwcssloader $loader */
        $loader = plugin_load('helper', 'odt_dwcssloader');
        if ( $loader != NULL ) {
            $this->css = $loader->load
                ('odt', 'odt', $this->config->getParam('css_template'));
        }

        // Import CSS (old API, deprecated)
        $this->import = plugin_load('helper', 'odt_cssimport');
        if ( $this->import != NULL ) {
            $this->import->importFromString ($this->css);

            // Call adjustLengthValues to make our callback function being called for every
            // length value imported. This gives us the chance to convert it once from
            // pixel to points.
            $this->import->adjustLengthValues (array($this, 'adjustLengthCallback'));
        }

        // Set CSS usage according to configuration
        switch ($this->config->getParam('css_usage')) {
            case 'basic style import':
                $this->document->setCSSUsage('basic');
                break;
            case 'full':
                $this->document->setCSSUsage('full');
                break;
            default:
                $this->document->setCSSUsage('off');
                break;
        }
        $this->document->setMediaSelector ($this->config->getParam('media_sel'));

        // Put some root element on the HTML stack which should always
        // be present for our CSS matching
        $this->document->addHTMLElement ('html', 'lang="'.$conf['lang'].'" dir="'.$lang['direction'].'"');
        $this->document->addHTMLElement ('body');
        $this->document->addHTMLElement ('div', 'id="dokuwiki__site"');
        $this->document->addHTMLElement ('div', 'id="dokuwiki__top" class="site dokuwiki mode_show tpl_adoradark loggedIn"');
        $this->document->addHTMLElement ('div', 'id="dokuwiki__content"');
        $this->document->addHTMLElement ('div', 'class="page group"');

        // Import CSS (new API)
        $this->document->importCSSFromString($this->css, $this->config->getParam('media_sel'), array($this, 'replaceURLPrefixesCallback'));
    }

    /**
     * Configure units to our configuration values.
     */
    protected function setupUnits()
    {
        $this->document->setPixelPerEm($this->config->getParam ('css_font_size'));
        $this->document->setTwipsPerPixelX($this->config->getParam ('twips_per_pixel_x'));
        $this->document->setTwipsPerPixelY($this->config->getParam ('twips_per_pixel_y'));
    }

    /**
     * Initialize the document,
     * Do the things that are common to all documents regardless of the
     * output format (ODT or PDF).
     */
    function document_setup()
    {
        global $ID;

        // First, get export mode.
        $warning = '';
        $mode = $this->config->load($warning);

        // Setup Units before CSS import!
        $this->setupUnits();

        // Import CSS files
        $this->load_css();

        switch($mode) {
            case 'ODT template':
                // Document based on ODT template.
                $this->buildODTPathes ($ODTtemplate, $temp_dir);
                $this->document->importODTStyles($ODTtemplate, $temp_dir);

                if ($this->config->getParam ('apply_fs_to_non_css')) {
                    $this->document->adjustFontSizes($this->config->getParam('css_font_size').'pt');
                }
                break;

            case 'CSS template':
                // Document based on DokuWiki CSS template.
                $media_sel = $this->config->getParam ('media_sel');
                $template = $this->config->getParam ('odt_template');
                $directory = $this->config->getParam ('tpl_dir');
                $template_path = $this->config->getParam('mediadir').'/'.$directory."/".$template;
                $this->document->importCSSFromFile($template_path, $media_sel, array($this, 'replaceURLPrefixesCallback'));

                // Set outline style.
                $this->document->setOutlineStyle($this->config->getParam('outline_list_style'));

                // Set ordered list alignment.
                $this->document->setOrderedListAlignment($this->config->getParam('olist_label_align'));
                break;

            default:
                // Document from scratch.

                // Set outline style.
                $this->document->setOutlineStyle($this->config->getParam('outline_list_style'));

                // Set ordered list alignment.
                $this->document->setOrderedListAlignment($this->config->getParam('olist_label_align'));

                if ($this->config->getParam ('apply_fs_to_non_css')) {
                    $this->document->adjustFontSizes($this->config->getParam('css_font_size').'pt');
                }
                break;
        }

        // Setup page format.
        $this->document->setStartPageFormat ($this->config->getParam ('format'),
                                             $this->config->getParam ('orientation'),
                                             $this->config->getParam ('margin_top'),
                                             $this->config->getParam ('margin_right'),
                                             $this->config->getParam ('margin_bottom'),
                                             $this->config->getParam ('margin_left'));

        // Set title in meta info.
        // FIXME article title != book title  SOLUTION: overwrite at the end for book
        $this->document->setTitle($ID);

        // Enable/disable links according to configuration
        $disabled = $this->config->getParam ('disable_links');
        if ($disabled) {
            $this->document->disableLinks();
        } else {
            $this->document->enableLinks();
        }

        // If older or equal to 2007-06-26, we need to disable caching
        $dw_version = preg_replace('/[^\d]/', '', getversion());  //FIXME DEPRECATED
        if (version_compare($dw_version, "20070626", "<=")) {
            $this->info["cache"] = false;
        }

        $this->set_page_bookmark($ID);
    }
    
    /**
     * Initialize the rendering
     */
    function document_start() {
        global $ID;

        // Initialize the document
        $this->document_setup();

        // Create HTTP headers
        $output_filename = str_replace(':','-',$ID).'.odt';
        $headers = array(
            'Content-Type' => 'application/vnd.oasis.opendocument.text',
            'Content-Disposition' => 'attachment; filename="'.$output_filename.'";',
        );

        // store the content type headers in metadata
        p_set_metadata($ID,array('format' => array('odt_page' => $headers) ));
    }

    /**
     * Closes the document
     */
    function document_end(){
        // Build the document
        $this->finalize_ODTfile();

        // Refresh certain config parameters e.g. 'disable_links'
        $this->config->refresh();

        // Reset state.
        $this->document->state->reset();
    }

    /**
     * This function sets the page format.
     * The format, orientation and page margins can be changed.
     * See function queryFormat() in ODT/page.php for supported formats.
     *
     * @param string  $format         e.g. 'A4', 'A3'
     * @param string  $orientation    e.g. 'portrait' or 'landscape'
     * @param numeric $margin_top     Top-Margin in cm, default 2
     * @param numeric $margin_right   Right-Margin in cm, default 2
     * @param numeric $margin_bottom  Bottom-Margin in cm, default 2
     * @param numeric $margin_left    Left-Margin in cm, default 2
     * @see ODTDocument::setPageFormat
     */
    public function setPageFormat ($format=NULL, $orientation=NULL, $margin_top=NULL, $margin_right=NULL, $margin_bottom=NULL, $margin_left=NULL) {
        $this->document->setPageFormat ($format, $orientation, $margin_top, $margin_right, $margin_bottom, $margin_left);
    }

    /**
     * Convert exported ODT file if required.
     * Supported formats: pdf
     */
    protected function convert () {
        global $ID;
                
        $format = $this->config->getConvertTo ();
        if ($format == 'pdf') {
            // Prepare temp directory
            $temp_dir = $this->config->getParam('tmpdir');
            $temp_dir = $temp_dir."/odt/".str_replace(':','-',$ID);
            if (is_dir($temp_dir)) { io_rmdir($temp_dir,true); }
            io_mkdir_p($temp_dir);

            // Set source and dest file path
            $file = $temp_dir.'/convert.odt';
            $pdf_file = $temp_dir.'/convert.pdf';

            // Prepare command line
            $command = $this->config->getParam('convert_to_pdf');
            $command = str_replace('%outdir%', $temp_dir, $command);
            $command = str_replace('%sourcefile%', $file, $command);

            // Convert file
            io_saveFile($file, $this->doc);
            exec ($command, $output, $result);
            if ($result) {
                $errormessage = '';
                foreach ($output as $line) {
                    $errormessage .= $this->_xmlEntities($line);
                }
                $message = $this->getLang('conversion_failed_msg');
                $message = str_replace('%command%', $command, $message);
                $message = str_replace('%errorcode%', $result, $message);
                $message = str_replace('%errormessage%', $errormessage, $message);
                $message = str_replace('%pageid%', $ID, $message);
                
                $instructions = p_get_instructions($message);
                $this->doc = p_render('xhtml', $instructions, $info);

                $headers = array(
                    'Content-Type' =>  'text/html; charset=utf-8',
                );
                p_set_metadata($ID,array('format' => array('odt_page' => $headers) ));
            } else {
                $this->doc = io_readFile($pdf_file, false);
            }
            io_rmdir($temp_dir,true);
        }
    }

    /**
     * Completes the ODT file.
     */
    public function finalize_ODTfile() {
        global $ID;

        $this->buildODTPathes ($ODTtemplate, $temp_dir);

        // Build/assign the document
        $this->doc = $this->document->getODTFileAsString ($ODTtemplate, $temp_dir);

        $this->convert();
    }

    /**
     * Simple setter to enable creating links.
     */
    function enable_links() {
        $this->config->setParam ('disable_links', false);
        $this->document->enableLinks();
    }

    /**
     * Simple setter to disable creating links.
     */
    function disable_links() {
        $this->config->setParam ('disable_links', true);
        $this->document->disableLinks();
    }

    /**
     * Dummy function.
     *
     * @return string
     */
    function render_TOC() {
        return '';
    }

    /**
     * This function does not really render an index but inserts a placeholder.
     *
     * @return string
     * @see ODTDocument::insertIndex for API wrapper function
     * @see ODTIndex::insertIndex for more information
     */
    function render_index($type='toc', $settings=NULL) {
        $data = array();
        $data = $this->get_index_settings($type, $settings);
        $this->document->insertIndex($type, $data);
        return '';
    }

    /**
     * This function detmerines the settings for a TOC or chapter index.
     * The layout settings are taken from the configuration and $settings.
     * The result is returned as an array.
     *
     * $settings can include the following options syntax:
     * - Title e.g. 'title=Example;'.
     *   Default is 'Table of Contents' (for english, see language files for other languages default value).
     * - Leader sign, e.g. 'leader-sign=.;'.
     *   Default is '.'.
     * - Indents (in cm), e.g. 'indents=indents=0,0.5,1,1.5,2,2.5,3;'.
     *   Default is 0.5 cm indent more per level.
     * - Maximum outline/TOC level, e.g. 'maxtoclevel=5;'.
     *   Default is taken from DokuWiki config setting 'maxtoclevel'.
     * - Insert pagebreak after TOC, e.g. 'pagebreak=1;'.
     *   Default is '1', means insert pagebreak after TOC.
     * - Set style per outline/TOC level, e.g. 'styleL2="color:red;font-weight:900;";'.
     *   Default is 'color:black'.
     *
     * It is allowed to use defaults for all settings by omitting $settings.
     * Multiple settings can be combined, e.g. 'leader-sign=.;indents=0,0.5,1,1.5,2,2.5,3;'.
     */
    protected function get_index_settings($type, $settings) {
        $matches = array();
        $data = array();

        // It seems to be not supported in ODT to have a different start
        // outline level than 1.
        $data ['maxlevel'] = $this->config->getParam('toc_maxlevel');
        if ( preg_match('/maxlevel=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 12);
            $temp = trim ($temp, ';');
            $data ['maxlevel'] = $temp;
        }

        // Determine title, default for table of contents is 'Table of Contents'.
        // Default for chapter index is empty.
        // Syntax for 'Test' as title would be "title=test;".
        $data ['title'] = '';
        if ($type == 'toc') {
            $data ['title'] = $this->getLang('toc_title');
        }
        if ( preg_match('/title=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 6);
            $temp = trim ($temp, ';');
            $data ['title'] = $temp;
        }

        // Determine leader-sign, default is '.'.
        // Syntax for '.' as leader-sign would be "leader_sign=.;".
        $data ['leader_sign'] = $this->config->getParam('toc_leader_sign');
        if ( preg_match('/leader_sign=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 12);
            $temp = trim ($temp, ';');
            $data ['leader_sign'] = $temp [0];
        }

        // Determine indents, default is '0.5' (cm) per level.
        // Syntax for a indent of '0.5' for 5 levels would be "indents=0,0.5,1,1.5,2;".
        // The values are absolute for each level, not relative to the higher level.
        $data ['indents'] = explode (',', $this->config->getParam('toc_indents'));
        if ( preg_match('/indents=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 8);
            $temp = trim ($temp, ';');
            $data ['indents'] = explode (',', $temp);
        }

        // Determine pagebreak, default is on '1'.
        // Syntax for pagebreak off would be "pagebreak=0;".
        $data ['pagebreak'] = $this->config->getParam('toc_pagebreak');
        if ( preg_match('/pagebreak=[^;]+;/', $settings, $matches) === 1 ) {
            $temp = substr ($matches [0], 10);
            $temp = trim ($temp, ';');
            $data ['pagebreak'] = false;            
            if ( $temp == '1' ) {
                $data ['pagebreak'] = true;
            } else if ( strcasecmp($temp, 'true') == 0 ) {
                $data ['pagebreak'] = true;
            }
        }

        // Determine text style for the index heading.
        $data ['style_heading'] = NULL;
        if ( preg_match('/styleH="[^"]+";/', $settings, $matches) === 1 ) {
            $quote = strpos ($matches [0], '"');
            $temp = substr ($matches [0], $quote+1);
            $temp = trim ($temp, '";');
            $data ['style_heading'] = $temp.';';
        }

        // Determine text styles per level.
        // Syntax for a style level 1 is "styleL1="color:black;"".
        // The default style is just 'color:black;'.
        for ( $count = 0 ; $count < $data ['maxlevel'] ; $count++ ) {
            $data ['styleL'.($count + 1)] = $this->config->getParam('toc_style');
            if ( preg_match('/styleL'.($count + 1).'="[^"]+";/', $settings, $matches) === 1 ) {
                $quote = strpos ($matches [0], '"');
                $temp = substr ($matches [0], $quote+1);
                $temp = trim ($temp, '";');
                $data ['styleL'.($count + 1)] = $temp.';';
            }
        }
        
        return $data;
    }

    /**
     * Add an item to the TOC
     * (Dummy function required by the Doku_Renderer class)
     *
     * @param string $id       the hash link
     * @param string $text     the text to display
     * @param int    $level    the nesting level
     */
    function toc_additem($id, $text, $level) {}

    /**
     * Return total page width in centimeters
     * (margins are included)
     *
     * @see ODTDocument::getWidth for API wrapper function
     * @see pageFormat::getWidth for more information
     * @author LarsDW223
     */
    function _getPageWidth(){
        return $this->document->getWidth();
    }

    /**
     * Return total page height in centimeters
     * (margins are included)
     *
     * @see ODTDocument::getHeight for API wrapper function
     * @see pageFormat::getHeight for more information
     * @author LarsDW223
     */
    function _getPageHeight(){
        return $this->document->getHeight();
    }

    /**
     * Return left margin in centimeters
     *
     * @see ODTDocument::getMarginLeft for API wrapper function
     * @see pageFormat::getMarginLeft for more information
     * @author LarsDW223
     */
    function _getLeftMargin(){
        return $this->document->getMarginLeft();
    }

    /**
     * Return right margin in centimeters
     *
     * @see ODTDocument::getMarginRight for API wrapper function
     * @see pageFormat::getMarginRight for more information
     * @author LarsDW223
     */
    function _getRightMargin(){
        return $this->document->getMarginRight();
    }

    /**
     * Return top margin in centimeters
     *
     * @see ODTDocument::getMarginTop for API wrapper function
     * @see pageFormat::getMarginTop for more information
     * @author LarsDW223
     */
    function _getTopMargin(){
        return $this->document->getMarginTop();
    }

    /**
     * Return bottom margin in centimeters
     *
     * @see ODTDocument::getMarginBottom for API wrapper function
     * @see pageFormat::getMarginBottom for more information
     * @author LarsDW223
     */
    function _getBottomMargin(){
        return $this->document->getMarginBottom();
    }

    /**
     * Return width percentage value if margins are taken into account.
     * Usually "100%" means 21cm in case of A4 format.
     * But usually you like to take care of margins. This function
     * adjusts the percentage to the value which should be used for margins.
     * So 100% == 21cm e.g. becomes 80.9% == 17cm (assuming a margin of 2 cm on both sides).
     *
     * @param int|string $percentage
     * @return int|string
     * 
     * @see ODTDocument::getRelWidthMindMargins for API wrapper function
     * @see pageFormat::getRelWidthMindMargins for more information
     * @author LarsDW223
     */
    function _getRelWidthMindMargins ($percentage = '100'){
        return $this->document->getRelWidthMindMargins($percentage);
    }

    /**
     * Like _getRelWidthMindMargins but returns the absulute width
     * in centimeters.
     *
     * @param string|int|float $percentage
     * @return float
     * 
     * @see ODTDocument::getAbsWidthMindMargins for API wrapper function
     * @see pageFormat::getAbsWidthMindMargins for more information
     * @author LarsDW223
     */
    function _getAbsWidthMindMargins ($percentage = '100'){
        return $this->document->getAbsWidthMindMargins($percentage);
    }

    /**
     * Return height percentage value if margins are taken into account.
     * Usually "100%" means 29.7cm in case of A4 format.
     * But usually you like to take care of margins. This function
     * adjusts the percentage to the value which should be used for margins.
     * So 100% == 29.7cm e.g. becomes 86.5% == 25.7cm (assuming a margin of 2 cm on top and bottom).
     *
     * @param string|float|int $percentage
     * @return float|string
     * 
     * @see ODTDocument::getRelHeightMindMargins for API wrapper function
     * @see pageFormat::getRelHeightMindMargins for more information
     * @author LarsDW223
     */
    function _getRelHeightMindMargins ($percentage = '100'){
        return $this->document->getRelHeightMindMargins($percentage);
    }

    /**
     * Like _getRelHeightMindMargins but returns the absulute width
     * in centimeters.
     *
     * @param string|int|float $percentage
     * @return float
     * 
     * @see ODTDocument::getAbsHeightMindMargins for API wrapper function
     * @see pageFormat::getAbsHeightMindMargins for more information
     * @author LarsDW223
     */
    function _getAbsHeightMindMargins ($percentage = '100'){
        return $this->document->getAbsHeightMindMargins($percentage);
    }

    /**
     * Render plain text data.
     *
     * @param string $text
     * @see ODTDocument::addPlainText for more information
     */
    function cdata($text) {
        $this->document->addPlainText($text);
    }

    /**
     * Open a paragraph.
     *
     * @param string $style Name of the style to use for the paragraph
     * 
     * @see ODTDocument::paragraphOpen for API wrapper function
     * @see ODTParagraph::paragraphOpen for more information
     */
    function p_open($style=NULL){
        $this->document->paragraphOpen($style);
    }

    /**
     * Close a paragraph.
     *
     * @see ODTDocument::paragraphClose for API wrapper function
     * @see ODTParagraph::paragraphClose for more information
     */
    function p_close(){
        $this->document->paragraphClose();
    }

    /**
     * Set bookmark for the start of the page. This just saves the title temporarily.
     * It is then to be inserted in the first header or paragraph.
     *
     * @param string $id    ID of the bookmark
     */
    function set_page_bookmark($id){
        $this->document->setPageBookmark($id);
    }

    /**
     * Render a heading
     *
     * @param string $text  the text to display
     * @param int    $level header level
     * @param int    $pos   byte position in the original source
     */
    function header($text, $level, $pos){
        $this->document->heading($text, $level);
    }

    function hr() {
        $this->document->horizontalRule();
    }

    function linebreak() {
        $this->document->linebreak();
    }

    function pagebreak() {
        $this->document->pagebreak();
    }

    function strong_open() {
        $this->document->spanOpen($this->document->getStyleName('strong'));
    }

    function strong_close() {
        $this->document->spanClose();
    }

    function emphasis_open() {
        $this->document->spanOpen($this->document->getStyleName('emphasis'));
    }

    function emphasis_close() {
        $this->document->spanClose();
    }

    function underline_open() {
        $this->document->spanOpen($this->document->getStyleName('underline'));
    }

    function underline_close() {
        $this->document->spanClose();
    }

    function monospace_open() {
        $this->document->spanOpen($this->document->getStyleName('monospace'));
    }

    function monospace_close() {
        $this->document->spanClose();
    }

    function subscript_open() {
        $this->document->spanOpen($this->document->getStyleName('sub'));
    }

    function subscript_close() {
        $this->document->spanClose();
    }

    function superscript_open() {
        $this->document->spanOpen($this->document->getStyleName('sup'));
    }

    function superscript_close() {
        $this->document->spanClose();
    }

    function deleted_open() {
        $this->document->spanOpen($this->document->getStyleName('del'));
    }

    function deleted_close() {
        $this->document->spanClose();
    }

    function generateSpansfromHTMLCode($HTMLCode){
        $this->document->generateSpansfromHTMLCode($HTMLCode);
    }

    /*
     * Tables
     */

    /**
     * Start a table
     *
     * @param int $maxcols maximum number of columns
     * @param int $numrows NOT IMPLEMENTED
     */
    function table_open($maxcols = NULL, $numrows = NULL, $pos = NULL){
        $this->document->tableOpen($maxcols, $numrows);
    }

    function table_close($pos = NULL){
        $this->document->tableClose();
    }

    function tablecolumn_add(){
        $this->document->tableAddColumn();
    }

    function tablerow_open(){
        $this->document->tableRowOpen();
    }

    function tablerow_close(){
        $this->document->tableRowClose();
    }

    /**
     * Open a table header cell
     *
     * @param int    $colspan
     * @param string $align left|center|right
     * @param int    $rowspan
     */
    function tableheader_open($colspan = 1, $align = "left", $rowspan = 1){
        $this->document->tableHeaderOpen($colspan, $rowspan, $align);
    }

    function tableheader_close(){
        $this->document->tableHeaderClose();
    }

    /**
     * Open a table cell
     *
     * @param int    $colspan
     * @param string $align left|center|right
     * @param int    $rowspan
     */
    function tablecell_open($colspan = 1, $align = "left", $rowspan = 1){
        $this->document->tableCellOpen($colspan, $rowspan, $align);
    }

    function tablecell_close(){
        $this->document->tableCellClose();
    }

    /**
     * Callback for footnote start syntax.
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function footnote_open() {
        $this->document->footnoteOpen();
    }

    /**
     * Callback for footnote end syntax.
     *
     * @author Andreas Gohr
     */
    function footnote_close() {
        $this->document->footnoteClose();
    }

    function listu_open($continue=false) {
        $this->document->listOpen($continue, $this->document->getStyleName('list'), 'ul');
    }

    function listu_close() {
        $this->document->listClose();
    }

    function listo_open($continue=false) {
        $this->document->listOpen($continue, $this->document->getStyleName('numbering'), 'ol');
    }

    function listo_close() {
        $this->document->listClose();
    }

    function list_close() {
        $this->document->listClose();
    }

    /**
     * Open a list item
     *
     * @param int $level the nesting level
     */
    function listitem_open($level, $node = false) {
        $this->document->listItemOpen($level);
    }

    function listitem_close() {
        $this->document->listItemClose();
    }

    /**
     * Open a list header
     *
     * @param int $level the nesting level
     */
    function listheader_open($level) {
        $this->document->listHeaderOpen($level);
    }

    function listheader_close() {
        $this->document->listHeaderClose();
    }

    function listcontent_open() {
        $this->document->listContentOpen();
    }

    function listcontent_close() {
        $this->document->listContentClose();
    }

    /**
     * Output unformatted $text
     *
     * @param string $text
     */
    function unformatted($text) {
        $this->document->addPlainText($text);
    }

    /**
     * Format an acronym
     *
     * @param string $acronym
     */
    function acronym($acronym) {
        $this->document->addPlainText($acronym);
    }

    /**
     * @param string $smiley
     */
    function smiley($smiley) {
        if ( array_key_exists($smiley, $this->smileys) ) {
            $src = DOKU_INC."lib/images/smileys/".$this->smileys[$smiley];
            $this->_odtAddImage($src);
        } else {
            $this->document->addPlainText($smiley);
        }
    }

    /**
     * Format an entity
     *
     * @param string $entity
     */
    function entity($entity) {
        // Add plain text will replace entities
        $this->document->addPlainText($entity);
    }

    /**
     * Typographically format a multiply sign
     *
     * Example: ($x=640, $y=480) should result in "640×480"
     *
     * @param string|int $x first value
     * @param string|int $y second value
     */
    function multiplyentity($x, $y) {
        $text .= $x.'×'.$y;
        $this->document->addPlainText($text);
    }

    function singlequoteopening() {
        global $lang;
        $text .= $lang['singlequoteopening'];
        $this->document->addPlainText($text);
    }

    function singlequoteclosing() {
        global $lang;
        $text .= $lang['singlequoteclosing'];
        $this->document->addPlainText($text);
    }

    function apostrophe() {
        global $lang;
        $text .= $lang['apostrophe'];
        $this->document->addPlainText($text);
    }

    function doublequoteopening() {
        global $lang;
        $text .= $lang['doublequoteopening'];
        $this->document->addPlainText($text);
    }

    function doublequoteclosing() {
        global $lang;
        $text .= $lang['doublequoteclosing'];
        $this->document->addPlainText($text);
    }

    /**
     * Output inline PHP code
     *
     * @param string $text The PHP code
     */
    function php($text) {
        $this->monospace_open();
        $this->document->addPlainText($text);
        $this->monospace_close();
    }

    /**
     * Output block level PHP code
     *
     * @param string $text The PHP code
     */
    function phpblock($text) {
        $this->file($text);
    }

    /**
     * Output raw inline HTML
     *
     * @param string $text The HTML
     */
    function html($text) {
        $this->monospace_open();
        $this->document->addPlainText($text);
        $this->monospace_close();
    }

    /**
     * Output raw block-level HTML
     *
     * @param string $text The HTML
     */
    function htmlblock($text) {
        $this->file($text);
    }

    /**
     * Output preformatted text
     *
     * @param string $text
     */
    function preformatted($text) {
        $this->_preformatted($text);
    }

    /**
     * Display text as file content, optionally syntax highlighted
     *
     * @param string $text text to show
     * @param string $language programming language to use for syntax highlighting
     * @param string $filename file path label
     */
    function file($text, $language=null, $filename=null) {
        $this->_highlight('file', $text, $language);
    }

    function quote_open() {
        $this->document->quoteOpen();
    }

    function quote_close() {
        $this->document->quoteClose();        
    }

    /**
     * Display text as code content, optionally syntax highlighted
     *
     * @param string $text text to show
     * @param string $language programming language to use for syntax highlighting
     * @param string $filename file path label
     */
    function code($text, $language=null, $filename=null) {
        $this->_highlight('code', $text, $language);
    }

    /**
     * @param string $text
     * @param string $style
     * @param bool $notescaped
     */
    function _preformatted($text, $style=null, $notescaped=true) {
        $this->document->addPreformattedText($text, $style, $notescaped);
    }

    /**
     * @param string $type
     * @param string $text
     * @param string $language
     */
    function _highlight($type, $text, $language=null) {
        $style_name = $this->document->getStyleName('source code');
        if ($type == "file") $style_name = $this->document->getStyleName('source file');

        if (is_null($language)) {
            $this->_preformatted($text, $style_name);
            return;
        }

        // Use cahched geshi
        $highlighted_code = p_xhtml_cached_geshi($text, $language, '');

        // remove useless leading and trailing whitespace-newlines
        $highlighted_code = preg_replace('/^&nbsp;\n/','',$highlighted_code);
        $highlighted_code = preg_replace('/\n&nbsp;$/','',$highlighted_code);
        // replace styles
        $highlighted_code = str_replace("</span>", "</text:span>", $highlighted_code);
        $highlighted_code = preg_replace_callback('/<span class="([^"]+)">/', array($this, '_convert_css_styles'), $highlighted_code);
        // cleanup leftover span tags
        $highlighted_code = preg_replace('/<span[^>]*>/', "<text:span>", $highlighted_code);
        $highlighted_code = str_replace("&nbsp;", "&#xA0;", $highlighted_code);
        // Replace links with ODT link syntax
        $highlighted_code = preg_replace_callback('/<a (href="[^"]*">.*?)<\/a>/', array($this, '_convert_geshi_links'), $highlighted_code);

        $this->_preformatted($highlighted_code, $style_name, false);
    }

    /**
     * @param array $matches
     * @return string
     */
    function _convert_css_styles($matches) {
        $class = $matches[1];
        
        // Get CSS properties for that geshi class and create
        // the text style (if not already done)
        $style_name = 'highlight_'.$class;
        if (!$this->document->styleExists($style_name)) {
            $properties = array();
            $properties ['style-name'] = $style_name;
            $this->getODTProperties ($properties, NULL, 'code '.$class, NULL, 'screen');

            // Create automatic style
            $this->document->createTextStyle($properties, false);
        }
        
        // Now make use of the new style
        return '<text:span text:style-name="'.$style_name.'">';
    }

    /**
     * Callback function which creates a link from the part 'href="[^"]*">.*'
     * in the pattern /<a (href="[^"]*">.*)<\/a>/. See function _highlight().
     * 
     * @param array $matches
     * @return string
     */
    function _convert_geshi_links($matches) {
        $content_start = strpos ($matches[1], '>');
        $content = substr ($matches[1], $content_start+1);
        preg_match ('/href="[^"]*"/', $matches[1], $urls);
        $url = substr ($urls[0], 5);
        $url = trim($url, '"');
        // Keep '&' and ':' in the link unescaped, otherwise url parameter passing will not work
        $url = str_replace('&amp;', '&', $url);
        $url = str_replace('%3A', ':', $url);

        return $this->_doLink($url, $content, true);
    }

    /**
     * Render an internal media file
     *
     * @param string $src       media ID
     * @param string $title     descriptive text
     * @param string $align     left|center|right
     * @param int    $width     width of media in pixel
     * @param int    $height    height of media in pixel
     * @param string $cache     cache|recache|nocache
     * @param string $linking   linkonly|detail|nolink
     * @param bool   $returnonly whether to return odt or write to doc attribute
     */
    function internalmedia ($src, $title=NULL, $align=NULL, $width=NULL,
                            $height=NULL, $cache=NULL, $linking=NULL, $returnonly = false) {
        global $ID;
        resolve_mediaid(getNS($ID),$src, $exists);
        list(/* $ext */,$mime) = mimetype($src);

        if ($linking == 'linkonly') {
            $url = str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true));
            if (empty($title)) {
                $title = $src;
            }
            if ($returnonly) {
                return $this->externallink($url, $title, true);
            } else {
                $this->externallink($url, $title);
            }
            return;
        }

        if(substr($mime,0,5) == 'image'){
            $file = mediaFN($src);
            if($returnonly) {
              return $this->_odtAddImage($file, $width, $height, $align, $title, NULL, true);
            } else {
              $this->_odtAddImage($file, $width, $height, $align, $title);
            }
        }else{
/*
            // FIXME build absolute medialink and call externallink()
            $this->code('FIXME internalmedia: '.$src);
*/
            //FIX by EPO/Intersel - create a link to the dokuwiki internal resource
            if (empty($title)) {$title=explode(':',$src); $title=end($title);}
            if($returnonly) {
              return $this->externalmedia(str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true)),$title,
                                        null, null, null, null, null, true);
            } else {
              $this->externalmedia(str_replace('doku.php?id=','lib/exe/fetch.php?media=',wl($src,'',true)),$title,
                                        null, null, null, null, null);
            }
            //End of FIX
        }
    }

    /**
     * Render an external media file
     *
     * @param string $src        full media URL
     * @param string $title      descriptive text
     * @param string $align      left|center|right
     * @param int    $width      width of media in pixel
     * @param int    $height     height of media in pixel
     * @param string $cache      cache|recache|nocache
     * @param string $linking    linkonly|detail|nolink
     * @param bool   $returnonly whether to return odt or write to doc attribute
     */
    function externalmedia ($src, $title=NULL, $align=NULL, $width=NULL,
                            $height=NULL, $cache=NULL, $linking=NULL, $returnonly = false) {
        list($ext,$mime) = mimetype($src);

        if ($linking == 'linkonly') {
            $url = $src;
            if (empty($title)) {
                $title = $src;
            }
            if ($returnonly) {
                return $this->externallink($url, $title, true);
            } else {
                $this->externallink($url, $title);
            }
            return;
        }

        if(substr($mime,0,5) == 'image'){
            $tmp_dir = $this->config->getParam ('tmpdir')."/odt";
            $tmp_name = $tmp_dir."/".md5($src).'.'.$ext;
            $client = new DokuHTTPClient;
            $img = $client->get($src);
            if ($img === FALSE) {
                $tmp_name = $src; // fallback to a simple link
            } else {
                if (!is_dir($tmp_dir)) io_mkdir_p($tmp_dir);
                $tmp_img = fopen($tmp_name, "w") or die("Can't create temp file $tmp_img");
                fwrite($tmp_img, $img);
                fclose($tmp_img);
            }

            $doc = '';
            if ($linking != 'nolink') {
                $doc .= $this->document->openImageLink ($src, $returnonly);
            }
            $doc .= $this->_odtAddImage($tmp_name, $width, $height, $align, $title, $returnonly);
            if ($linking != 'nolink') {
                $doc .= $this->document->closeImageLink ($returnonly);
            }
            if (file_exists($tmp_name)) unlink($tmp_name);

            return $doc;
        }else{
            if($returnonly) {
              return $this->externallink($src,$title,true);
            } else {
              $this->externallink($src,$title);
            }
        }
    }

    /**
     * Render a CamelCase link
     *
     * @param string $link       The link name
     * @param bool   $returnonly whether to return odt or write to doc attribute
     * @see http://en.wikipedia.org/wiki/CamelCase
     */
    function camelcaselink($link, $returnonly = false) {
        if($returnonly) {
          return $this->internallink($link,$link, null, true);
        } else {
          $this->internallink($link, $link);
        }
    }

    /**
     * This function is only used for the DokuWiki specific
     * 'returnonly' behaviour.
     * 
     * @param string $id
     * @param string $name
     */
    function reference($id, $name = NULL) {
        $ret = '<text:a xlink:type="simple" xlink:href="#'.$id.'"';
        if ($name) {
            $ret .= '>'.$this->_xmlEntities($name).'</text:a>';
        } else {
            $ret .= '/>';
        }
        return $ret;
    }

    /**
     * Render a wiki internal link
     *
     * @param string       $id         page ID to link to. eg. 'wiki:syntax'
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function internallink($id, $name = NULL, $returnonly = false) {
        global $ID;
        // default name is based on $id as given
        $default = $this->_simpleTitle($id);
        // now first resolve and clean up the $id
        resolve_pageid(getNS($ID),$id,$exists);
        $name = $this->_getLinkTitle($name, $default, $isImage, $id);

        // build the absolute URL (keeping a hash if any)
        list($id,$hash) = explode('#',$id,2);
        $url = wl($id,'',true);
        if($hash) $url .='#'.$hash;

        if ($ID == $id) {
            if($returnonly) {
                return $this->locallink_with_text($hash, $id, $name, $returnonly);
            } else {
                $this->locallink_with_text($hash, $id, $name, $returnonly);
            }
        } else {
            if($returnonly) {
                return $this->_doLink($url, $name, $returnonly);
            } else {
                $this->_doLink($url, $name, $returnonly);
            }
        }
    }

    /**
     * Add external link
     *
     * @param string       $url        full URL with scheme
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function externallink($url, $name = NULL, $returnonly = false) {
        $name = $this->_getLinkTitle($name, $url, $isImage);

        if($returnonly) {
            return $this->_doLink($url, $name, $returnonly);
        } else {
            $this->_doLink($url, $name, $returnonly);
        }
    }

    /**
     * Inserts a local link with text.
     *
     * @fixme add image handling
     *
     * @param string $hash hash link identifier
     * @param string $id   name for the link (the reference)
     * @param string $text text for the link (text inserted instead of reference)
     */
    function locallink_with_text($hash, $id = NULL, $text = NULL, $returnonly = false){
        if (!$returnonly) {
            $id  = $this->_getLinkTitle($id, $hash, $isImage);
            $this->document->insertCrossReference($id, $text);
        } else {
            return reference($hash, $name);
        }
    }

    /**
     * Inserts a local link.
     *
     * @fixme add image handling
     *
     * @param string $hash hash link identifier
     * @param string $name name for the link
     */
    function locallink($hash, $name = NULL){
        $name  = $this->_getLinkTitle($name, $hash, $isImage);
        $this->document->insertCrossReference($hash, $name);
    }

    /**
     * Render an interwiki link
     *
     * You may want to use $this->_resolveInterWiki() here
     *
     * @param string       $match      original link - probably not much use
     * @param string|array $name       name for the link, array for media file
     * @param string       $wikiName   indentifier (shortcut) for the remote wiki
     * @param string       $wikiUri    the fragment parsed from the original link
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function interwikilink($match, $name = NULL, $wikiName, $wikiUri, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $wikiUri, $isImage);
        $url = $this-> _resolveInterWiki($wikiName,$wikiUri);
        if($returnonly) {
            return $this->_doLink($url, $name, $returnonly);
        } else {
            $this->_doLink($url, $name, $returnonly);
        }
    }

    /**
     * Just print WindowsShare links
     *
     * @fixme add image handling
     *
     * @param string       $url        the link
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function windowssharelink($url, $name = NULL, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $url, $isImage);
        if($returnonly) {
            return $name;
        } else {
            $this->document->addPlainText($name);
        }
    }

    /**
     * Just print email links
     *
     * @fixme add image handling
     *
     * @param string       $address    Email-Address
     * @param string|array $name       name for the link, array for media file
     * @param bool         $returnonly whether to return odt or write to doc attribute
     */
    function emaillink($address, $name = NULL, $returnonly = false) {
        $name  = $this->_getLinkTitle($name, $address, $isImage);
        if($returnonly) {
            return $this->_doLink("mailto:".$address, $name, $returnonly);
        } else {
            $this->_doLink("mailto:".$address, $name, $returnonly);
        }
    }

    /**
     * Add a hyperlink, handling Images correctly
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     *
     * @param string $url
     * @param string|array $name
     */
    function _doLink($url,$name, $returnonly = false){
        $url = $this->_xmlEntities($url);
        $doc = '';
        if(is_array($name)){
            // Images
            $doc .= $this->document->openImageLink ($url, $returnonly);

            if($name['type'] == 'internalmedia'){
                $doc .= $this->internalmedia($name['src'],
                                     $name['title'],
                                     $name['align'],
                                     $name['width'],
                                     $name['height'],
                                     $name['cache'],
                                     $name['linking'],
                                     $returnonly);
            }

            $doc .= $this->document->closeImageLink ($returnonly);
        }else{
            // Text
            $doc .= $this->document->insertHyperlink ($url, $name, NULL, NULL, $returnonly);
        }
        return $doc;
    }

    /**
     * Construct a title and handle images in titles
     *
     * @author Harry Fuecks <hfuecks@gmail.com>
     *
     * @param string|array|null $title
     * @param string $default
     * @param bool|null $isImage
     * @param string $id
     * @return mixed
     */
    function _getLinkTitle($title, $default, & $isImage, $id=null) {
        $isImage = false;
        if ( is_array($title) ) {
            $isImage = true;
            return $title;
        } elseif (is_null($title) || trim($title) == '') {
            if ($this->config->getParam ('useheading') && $id) {
                $heading = p_get_first_heading($id);
                if ($heading) {
                    return $this->_xmlEntities($heading);
                }
            }
            return $this->_xmlEntities($default);
        } else {
            return $this->_xmlEntities($title);
        }
    }

    /**
     * @param string $value
     * @return string
     */
    function _xmlEntities($value) {
        return str_replace( array('&','"',"'",'<','>'), array('&#38;','&#34;','&#39;','&#60;','&#62;'), $value);
    }

    /**
     * Render the output of an RSS feed
     *
     * @param string $url    URL of the feed
     * @param array  $params Finetuning of the output
     */
    function rss ($url,$params){
        global $lang;

        require_once(DOKU_INC . 'inc/FeedParser.php');
        $feed = new FeedParser();
        $feed->feed_url($url);

        //disable warning while fetching
        $elvl = null;
        if (!defined('DOKU_E_LEVEL')) { $elvl = error_reporting(E_ERROR); }
        $rc = $feed->init();
        if (!defined('DOKU_E_LEVEL')) { error_reporting($elvl); }

        //decide on start and end
        if($params['reverse']){
            $mod = -1;
            $start = $feed->get_item_quantity()-1;
            $end   = $start - ($params['max']);
            $end   = ($end < -1) ? -1 : $end;
        }else{
            $mod   = 1;
            $start = 0;
            $end   = $feed->get_item_quantity();
            $end   = ($end > $params['max']) ? $params['max'] : $end;;
        }

        $this->listu_open();
        if($rc){
            for ($x = $start; $x != $end; $x += $mod) {
                $item = $feed->get_item($x);
                $this->document->listItemOpen(0);
                $this->document->listContentOpen();

                $this->externallink($item->get_permalink(),
                                    $item->get_title());
                if($params['author']){
                    $author = $item->get_author(0);
                    if($author){
                        $name = $author->get_name();
                        if(!$name) $name = $author->get_email();
                        if($name) $this->cdata(' '.$lang['by'].' '.$name);
                    }
                }
                if($params['date']){
                    $this->cdata(' ('.$item->get_date($this->config->getParam ('dformat')).')');
                }
                if($params['details']){
                    $this->cdata(strip_tags($item->get_description()));
                }
                $this->document->listContentClose();
                $this->document->listItemClose();
            }
        }else{
            $this->document->listItemOpen(0);
            $this->document->listContentOpen();
            $this->emphasis_open();
            $this->cdata($lang['rssfailed']);
            $this->emphasis_close();
            $this->externallink($url);
            $this->document->listContentClose();
            $this->document->listItemClose();
        }
        $this->listu_close();
    }

    /**
     * Adds the content of $string as a SVG picture to the document.
     * 
     * @see ODTDocument::addStringAsSVGImage for API wrapper function
     * @see ODTImage::addStringAsSVGImage for a detailed description
     */
    function _addStringAsSVGImage($string, $width = NULL, $height = NULL, $align = NULL, $title = NULL, $style = NULL) {
        $this->document->addStringAsSVGImage($string, $width, $height, $align, $title, $style);
    }

    /**
     * The function adds $string as an SVG image file.
     * It does NOT insert the image in the document.
     * 
     * @see ODTDocument::addStringAsSVGImageFile for a detailed description
     * @see ODTImage::addStringAsSVGImageFile for a detailed description
     */
    function _addStringAsSVGImageFile($string) {
        return $this->document->addStringAsSVGImageFile($string);
    }

    /**
     * Adds the image $src as a picture file without adding it to the content
     * of the document. The link name which can be used for the ODT draw:image xlink:href
     * is returned. The caller is responsible for creating the frame and image tag
     * but therefore has full control over it. This means he can also set parameters
     * in the odt frame and image tag which can not be changed using the function _odtAddImage.
     *
     * @author LarsDW223
     *
     * @param string $src
     * @return string
     */
    function _odtAddImageAsFileOnly($src){
        return $this->document->addFileAsPicture($src);
    }

    /**
     * Adds an image $src to the document.
     * 
     * @param string  $src        The path to the image file
     * @param string  $width      Width of the picture (NULL=original size)
     * @param string  $height     Height of the picture (NULL=original size)
     * @param string  $align      Alignment
     * @param string  $title      Title
     * @param string  $style      Optional "draw:style-name"
     * @param boolean $returnonly Only return code
     * 
     * @see ODTDocument::addImage for API wrapper function
     * @see ODTImage::addImage for a detailed description
     */
    function _odtAddImage($src, $width = NULL, $height = NULL, $align = NULL, $title = NULL, $style = NULL, $returnonly = false){
        if ($returnonly) {
            return $this->document->addImage($src, $width, $height, $align, $title, $style, $returnonly);
        } else {
            $this->document->addImage($src, $width, $height, $align, $title, $style, $returnonly);
        }
    }

    /**
     * Adds an image $src to the document using the parameters set in $properties.
     * 
     * @param string  $src        The path to the image file
     * @param array   $properties Properties (width, height... see ODTImage::addImageUseProperties)
     * @param boolean $returnonly Only return code
     * 
     * @see ODTDocument::addImageUseProperties for API wrapper function
     * @see ODTImage::addImageUseProperties for a detailed description
     */
    function _odtAddImageUseProperties($src, array $properties, $returnonly = false){
        if ($returnonly) {
            return $this->document->addImageUseProperties($src, $properties, $returnonly);
        } else {
            $this->document->addImageUseProperties($src, $properties, $returnonly);
        }
    }

    /**
     * The function tries to examine the width and height
     * of the image stored in file $src.
     * 
     * @see ODTDocument::getImageSize for API wrapper function
     * @see ODTUtility::getImageSize for a detailed description
     */
    public function _odtGetImageSize($src, $maxwidth=NULL, $maxheight=NULL){
        return $this->document->getImageSize($src, $maxwidth, $maxheight);
    }

    /**
     * @param string $src
     * @param  $width
     * @param  $height
     * @return array
     */
    function _odtGetImageSizeString($src, $width = NULL, $height = NULL){
        return $this->document->getImageSizeString($src, $width, $height);
    }

    /**
     * Open a span using CSS.
     * 
     * @see ODTDocument::spanOpenUseCSS for API wrapper function
     * @see ODTSpan::spanOpenUseCSS for detailed documentation
     * @author LarsDW223
     */
    function _odtSpanOpenUseCSS($element=NULL, $attributes=NULL, cssimportnew $import=NULL){
        $this->document->spanOpenUseCSS($element, $attributes, $import);
    }

    /**
     * Open a span using properties.
     * 
     * @see ODTDocument::spanOpenUseProperties for API wrapper function
     * @see ODTSpan::spanOpenUseProperties for detailed documentation
     * @author LarsDW223
     */
    function _odtSpanOpenUseProperties($properties){
        $this->document->spanOpenUseProperties($properties);
    }

    function _odtSpanOpen($style_name){
        $this->document->spanOpen($style_name);
    }

    /**
     * This function closes a span (previously opened with _odtSpanOpenUseCSS).
     *
     * @author LarsDW223
     */
    function _odtSpanClose(){
        $this->document->spanClose();
    }

    /**
     * Open a paragraph using CSS.
     * 
     * @see ODTDocument::paragraphOpenUseCSS for API wrapper function
     * @see ODTParagraph::paragraphOpenUseCSS for detailed documentation
     * @author LarsDW223
     */
    function _odtParagraphOpenUseCSS($element=NULL, $attributes=NULL, cssimportnew $import=NULL){
        $this->document->paragraphOpenUseCSS($element, $attributes, $import);
    }

    /**
     * Open a paragraph using properties.
     * 
     * @see ODTDocument::paragraphOpenUseProperties for API wrapper function
     * @see ODTParagraph::paragraphOpenUseProperties for detailed documentation
     * @author LarsDW223
     */
    function _odtParagraphOpenUseProperties($properties){
        $this->document->paragraphOpenUseProperties($properties);
    }

    /**
     * Open a text box using CSS.
     * 
     * @see ODTDocument::openTextBoxUseCSS for API wrapper function
     * @see ODTFrame::openTextBoxUseCSS for detailed documentation
     */
    function _odtOpenTextBoxUseCSS ($element=NULL, $attributes=NULL, cssimportnew $import=NULL) {
        $this->document->openTextBoxUseCSS ($element, $attributes, $import);
    }

    /**
     * This function opens a div. As divs are not supported by ODT, it will be exported as a frame.
     * To be more precise, to frames will be created. One including a picture nad the other including the text.
     * A picture frame will only be created if a 'background-image' is set in the CSS style.
     *
     * The currently supported CSS properties are:
     * background-color, color, padding, margin, display, border-radius, min-height.
     * The background-image is simulated using a picture frame.
     * FIXME: Find a way to successfuly use the background-image in the graphic style (see comments).
     *
     * The div should be closed by calling '_odtDivCloseAsFrame'.
     *
     * @author LarsDW223
     *
     * @param array $properties
     */
    function _odtDivOpenAsFrameUseProperties ($properties) {
        dbg_deprecated('_odtOpenTextBoxUseProperties');
        $this->_odtOpenTextBoxUseProperties ($properties);
    }

    /**
     * This function closes a div/frame (previously opened with _odtDivOpenAsFrameUseCSS).
     *
     * @author LarsDW223
     */
    function _odtDivCloseAsFrame () {
        $this->_odtCloseTextBox();
    }

    /**
     * This function opens a new table using CSS.
     *
     * @author LarsDW223
     * @see ODTDocument::tableOpenUseCSS for API wrapper function
     * @see ODTTable::tableOpenUseCSS for detailed documentation
     */
    function _odtTableOpenUseCSS($maxcols = NULL, $numrows = NULL, $element=NULL, $attributes = NULL, cssimportnew $import = NULL){
        $this->document->tableOpenUseCSS($maxcols, $numrows, $element, $attributes, $import);
    }

    /**
     * This function opens a new table using properties.
     *
     * @author LarsDW223
     * @see ODTDocument::tableOpenUseProperties for API wrapper function
     * @see ODTTable::tableOpenUseProperties for detailed documentation
     */
    function _odtTableOpenUseProperties ($properties, $maxcols = 0, $numrows = 0){
        $this->document->tableOpenUseProperties ($properties, $maxcols, $numrows);
    }

    /**
     * This function closes a table.
     *
     * @author LarsDW223
     * @see ODTDocument::tableClose for API wrapper function
     * @see ODTTable::tableClose for detailed documentation
     */
    function _odtTableClose () {
        $this->document->tableClose();
    }

    /**
     * This function adds a new table column using properties.
     *
     * @author LarsDW223
     * @see ODTDocument::tableAddColumnUseProperties for API wrapper function
     * @see ODTTable::tableAddColumnUseProperties for detailed documentation
     */
    function _odtTableAddColumnUseProperties (array $properties = NULL){
        $this->document->tableAddColumnUseProperties($properties);
    }

    /**
     * This function opens a new table header using CSS.
     * The header should be closed by calling 'tableheader_close()'.
     *
     * @author LarsDW223
     * @see ODTDocument::tableHeaderOpenUseCSS for API wrapper function
     * @see ODTTable::tableHeaderOpenUseCSS for detailed documentation
     */
    function _odtTableHeaderOpenUseCSS($colspan = 1, $rowspan = 1, $element=NULL, $attributes=NULL, cssimportnew $import=NULL){
        $this->document->tableHeaderOpenUseCSS($colspan, $rowspan, $element, $attributes, $import);
    }

    /**
     * This function opens a new table header using properties.
     * The header should be closed by calling 'tableheader_close()'.
     *
     * @author LarsDW223
     * @see ODTDocument::tableHeaderOpenUseProperties for API wrapper function
     * @see ODTTable::tableHeaderOpenUseProperties for detailed documentation
     */
    function _odtTableHeaderOpenUseProperties ($properties = NULL, $colspan = 1, $rowspan = 1){
        $this->document->tableHeaderOpenUseProperties($properties, $colspan = 1, $rowspan = 1);
    }

    /**
     * This function opens a new table row using CSS.
     * The row should be closed by calling 'tablerow_close()'.
     *
     * @author LarsDW223
     * @see ODTDocument::tableRowOpenUseCSS for API wrapper function
     * @see ODTTable::tableRowOpenUseCSS for detailed documentation
     */
    function _odtTableRowOpenUseCSS($element=NULL, $attributes=NULL, cssimportnew $import=NULL){
        $this->document->tableRowOpenUseCSS($element, $attributes, $import);
    }

    /**
     * This function opens a new table row using properties.
     * The row should be closed by calling 'tablerow_close()'.
     *
     * @author LarsDW223
     * @see ODTDocument::tableRowOpenUseProperties for API wrapper function
     * @see ODTTable::tableRowOpenUseProperties for detailed documentation
     */
    function _odtTableRowOpenUseProperties ($properties){
        $this->document->tableRowOpenUseProperties($properties);
    }

    /**
     * This function opens a new table cell using CSS.
     * The cell should be closed by calling 'tablecell_close()'.
     *
     * @author LarsDW223
     * @see ODTDocument::tableCellOpenUseCSS for API wrapper function
     * @see ODTTable::tableCellOpenUseCSS for detailed documentation
     */
    function _odtTableCellOpenUseCSS($colspan = 1, $rowspan = 1, $element=NULL, $attributes=NULL, cssimportnew $import=NULL){
        $this->document->tableCellOpenUseCSS($colspan, $rowspan, $element, $attributes, $import);
    }

    /**
     * This function opens a new table cell using properties.
     * The cell should be closed by calling 'tablecell_close()'.
     *
     * @author LarsDW223
     * @see ODTDocument::tableCellOpenUseProperties for API wrapper function
     * @see ODTTable::tableCellOpenUseProperties for detailed documentation
     */
    function _odtTableCellOpenUseProperties ($properties, $colspan = 1, $rowspan = 1){
        $this->document->tableCellOpenUseProperties($properties, $colspan, $rowspan);
    }

    /**
     * Open a multi column text box in a frame using properties.
     * 
     * @see ODTDocument::openMultiColumnTextBoxUseProperties for API wrapper function
     * @see ODTFrame::openMultiColumnTextBoxUseProperties for detailed documentation
     */
    function _odtOpenMultiColumnFrame ($properties) {
        $this->document->openMultiColumnTextBoxUseProperties($properties);
    }

    /**
     * This function closes a multi column frame (previously opened with _odtOpenMultiColumnFrame).
     *
     * @see ODTDocument::closeTextBox for API wrapper function
     * @see ODTFrame::closeTextBox for detailed documentation
     * @author LarsDW223
     */
    function _odtCloseMultiColumnFrame () {
        $this->document->closeMultiColumnTextBox();
    }

    /**
     * Open a text box in a frame using properties.
     * 
     * @see ODTDocument::openTextBoxUseProperties for API wrapper function
     * @see ODTFrame::openTextBoxUseProperties for detailed documentation
     */
    function _odtOpenTextBoxUseProperties ($properties) {
        $this->document->openTextBoxUseProperties ($properties);
    }

    /**
     * This function closes a textbox.
     *
     * @see ODTDocument::closeTextBox for API wrapper function
     * @see ODTFrame::closeTextBox for detailed documentation
     * @author LarsDW223
     */
    function _odtCloseTextBox () {
        $this->document->closeTextBox();
    }

    /**
     * Open a frame using properties.
     * 
     * @see ODTDocument::openFrameUseProperties for API wrapper function
     * @see ODTFrame::openFrameUseProperties for detailed documentation
     */
    function _odtOpenFrameUseProperties ($properties) {
        $this->document->openFrameUseProperties ($properties);
    }

    /**
     * This function closes a frame.
     *
     * @see ODTDocument::closeFrame for API wrapper function
     * @see ODTFrame::closeFrame for detailed documentation
     * @author LarsDW223
     */
    function _odtCloseFrame () {
        $this->document->closeFrame();
    }

    /**
     * @param array $dest
     * @param $element
     * @param $classString
     * @param $inlineStyle
     */
    public function getODTProperties (&$dest, $element, $classString, $inlineStyle, $media_sel=NULL, $cssId=NULL) {
        if ($media_sel === NULL) {
            $media_sel = $this->config->getParam ('media_sel');
        }
        // Get properties for our class/element from imported CSS
        $this->import->getPropertiesForElement($dest, $element, $classString, $media_sel, $cssId);

        // Interpret and add values from style to our properties
        $this->document->getCSSStylePropertiesForODT($dest, $inlineStyle);

        // Adjust values for ODT
        //foreach ($dest as $property => $value) {
        //    $dest [$property] = $this->adjustValueForODT ($property, $value, 14);
        //}
        $this->document->adjustValuesForODT($dest);
    }

    /**
     * Replace a CSS URL value with the given path.
     * 
     * @param $URL CSS URL e.g. 'url(images/xyz.png);'
     * @param $replacement The local path
     * @return string The resulting complete file path
     */
    public function replaceURLPrefix ($URL, $replacement) {
        return $this->import->replaceURLPrefix ($URL, $replacement);
    }

    /**
     * Convert pixel to points (X axis).
     * 
     * @param $pixel value to convert
     * @return float The converted value in points
     * @see ODTDocument::toPoints for API wrapper function
     * @see ODTUnits::toPoints for detailed documentation
     */
    public function pixelToPointsX ($pixel) {
        return $this->document->toPoints($pixel, 'x');
    }

    /**
     * Convert pixel to points (Y axis).
     * 
     * @param $pixel value to convert
     * @return float The converted value in points
     * @see ODTDocument::toPoints for API wrapper function
     * @see ODTUnits::toPoints for detailed documentation
     */
    public function pixelToPointsY ($pixel) {
        return $this->document->toPoints($pixel, 'y');
    }

    /**
     * Adjust the given property for ODT.
     * 
     * @param $property The property name
     * @param $value The property value
     * @param int $emValue The conversion value for 'em' units
     * @return string The new, adjusted value
     * @see ODTUtility::adjustValueForODT for detailed documentation
     */
    public function adjustValueForODT ($property, $value) {
        return $this->document->adjustValueForODT ($property, $value);
    }

    /**
     * Callback function which adjusts all CSS length values to point.
     * 
     * @param $property The name of the current CSS property, e.g. 'border-left'
     * @param $value The current value from the original CSS code
     * @param $type There are 3 possible values:
     *              - LengthValueXAxis: the property represents a value on the X axis
     *              - LengthValueYAxis: the property represents a value on the Y axis
     *              - CSSValueType::StrokeOrBorderWidth: the property represents a stroke
     *                or border width
     * @return string The new, adjusted value for the property
     */
    public function adjustLengthCallback ($property, $value, $type) {
        // Replace px with pt (px does not seem to be supported by ODT)
        $length = strlen ($value);
        if ( $length > 2 && $value [$length-2] == 'p' && $value [$length-1] == 'x' ) {
            $number = trim($value, 'px');
            switch ($type) {
                case CSSValueType::LengthValueXAxis:
                    $adjusted = $this->pixelToPointsX($number).'pt';
                break;

                case CSSValueType::StrokeOrBorderWidth:
                    switch ($property) {
                        case 'border':
                        case 'border-left':
                        case 'border-right':
                        case 'border-top':
                        case 'border-bottom':
                            // border in ODT spans does not support 'px' units, so we convert it.
                            $adjusted = $this->pixelToPointsY($number).'pt';
                        break;

                        default:
                            $adjusted = $value;
                        break;
                    }
                break;

                case CSSValueType::LengthValueYAxis:
                default:
                    $adjusted = $this->pixelToPointsY($number).'pt';
                break;
            }
            return $adjusted;
        }
        return $value;
    }

    /**
     * This function read the template page and imports all cdata and code content
     * as additional CSS. ATTENTION: this might overwrite already imported styles
     * from an ODT or CSS template file.
     *
     * @param $pagename The name of the template page
     */
    public function read_templatepage ($pagename) {
        $instructions = p_cached_instructions(wikiFN($pagename));
        $text = '';
        foreach($instructions as $instruction) {
            if($instruction[0] == 'code') {
                $text .= $instruction[1][0];
            } elseif ($instruction[0] == 'cdata') {
                $text .= $instruction[1][0];
            }
        }

        $this->document->importCSSFromString($text, $this->config->getParam('media_sel'), array($this, 'replaceURLPrefixesCallback'), true);
    }

    /**
     * Get CSS properties for a given element and adjust them for ODT.
     *
     * @see ODTDocument::getODTProperties for more information
     */
    public function getODTPropertiesNew (&$dest, $element, $attributes=NULL, $media_sel=NULL, $inherit=true) {
        if ($media_sel === NULL) {
            $media_sel = $this->config->getParam ('media_sel');
        }
        $this->document->getODTProperties ($dest, $element, $attributes, $media_sel, $inherit);
    }

    public function getODTPropertiesFromElement (&$dest, iElementCSSMatchable $element, $media_sel=NULL, $inherit=true) {
        if ($media_sel === NULL) {
            $media_sel = $this->config->getParam ('media_sel');
        }
        $this->document->getODTPropertiesFromElement ($dest, $element, $media_sel, $inherit);
    }

    /**
     * This function creates a text style.
     * 
     * @see ODTDocument::createTextStyle for detailed desciption.
     */
    public function createTextStyle ($properties, $common=true) {
        $this->document->createTextStyle ($properties, $common);
    }

    /**
     * This function creates a paragraph style.
     * 
     * @see ODTDocument::createParagraphStyle for detailed desciption.
     */
    public function createParagraphStyle ($properties, $common=true) {
        $this->document->createParagraphStyle ($properties, $common);
    }

    /**
     * This function creates a table style.
     * 
     * @see ODTDocument::createTableStyle for detailed desciption.
     */
    public function createTableStyle ($properties, $common=true) {
        $this->document->createTableStyle ($properties, $common);
    }

    /**
     * This function creates a table row style.
     * 
     * @see ODTDocument::createTableRowStyle for detailed desciption.
     */
    public function createTableRowStyle ($properties, $common=true) {
        $this->document->createTableRowStyle ($properties, $common);
    }

    /**
     * This function creates a table cell style.
     * 
     * @see ODTDocument::createTableCellStyle for detailed  desciption.
     */
    public function createTableCellStyle ($properties, $common=true) {
        $this->document->createTableCellStyle ($properties, $common);
    }

    /**
     * This function creates a table column style.
     * 
     * @see ODTDocument::createTableColumnStyle for detailed desciption.
     */
    public function createTableColumnStyle ($properties, $common=true) {
        $this->document->createTableColumnStyle ($properties, $common);
    }

    public function styleExists ($style_name) {
        return $this->document->styleExists($style_name);
    }

    /**
     * Add a user field.
     * (Code has been adopted from the fields plugin)
     *
     * @param string $name The name of the field
     * @param string $value The value of the field
     * @author Aurelien Bompard <aurelien@bompard.org>
     * @see ODTDocument::addUserField for detailed desciption.
     */    
    public function addUserField($name, $value) {
        $this->document->addUserField($name, $value);
    }

    /**
     * Insert a user field reference.
     * (Code has been adopted from the fields plugin)
     *
     * @param string $name The name of the field
     * @author Aurelien Bompard <aurelien@bompard.org>
     * @see ODTDocument::insertUserField for detailed desciption.
     */    
    public function insertUserField($name) {
        $this->document->insertUserField($name);
    }

    protected function buildODTPathes (&$ODTTemplatePath, &$tempDirPath) {
        global $ID;

        // Temp dir
        if (is_dir($this->config->getParam('tmpdir'))) {
            $tempDirPath = $this->config->getParam('tmpdir');
        }
        $tempDirPath = $tempDirPath."/odt/".str_replace(':','-',$ID);

        // Eventually determine ODT template file
        $ODTTemplatePath = NULL;
        $template = $this->config->getParam ('odt_template');
        if (!empty($template)) {
            $ODTTemplatePath = $this->config->getParam('mediadir').'/'.$this->config->getParam ('tpl_dir')."/".$this->config->getParam ('odt_template');
        }
    }    

    public function addToValue ($value, $add) {
        return $this->document->addToValue ($value, $add);
    }

    public function subFromValue ($value, $sub) {
        return $this->document->subFromValue ($value, $sub);
    }

    public function getHTMLStack () {
        return $this->document->getHTMLStack ();
    }

    public function dumpHTMLStack () {
        $this->document->dumpHTMLStack ();
    }

    public function setOrderedListAlignment ($align) {
        $this->document->setOrderedListAlignment($align);
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
