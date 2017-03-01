<?php
/**
 * This file is part of ( \arabcoders\getid3 ) project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * getID3 original code Was written by James Heinrich <info@getid3.org>.
 * Code was converted to classes by Javier Spagnoletti <jspagnoletti@javierspagnoletti.com.ar>
 * Code was reorganized and updated by Abdulmohsen Almansour <admin@arabcoders.org>
 */

namespace arabcoders\getid3;

use arabcoders\getid3\Exception\DefaultException;
use arabcoders\getid3\Lib\Helper;

/**
 * Class GetId3Core
 *
 * @package arabcoders\getid3
 */
class GetId3Core
{
    // public: Settings

    /**
     * CASE SENSITIVE! - i.e. (must be supported by {@see iconv}).
     * Examples:  ISO-8859-1 UTF-8 UTF-16 UTF-16BE
     *
     * @var string
     */
    public $encoding = 'UTF-8';

    /**
     * Should always be 'ISO-8859-1', but some tags may be written in other encodings such as 'EUC-CN' or 'CP1252'
     *
     * @var string
     */
    public $encoding_id3v1 = 'ISO-8859-1';

    // public: Optional tag checks - disable for speed.

    /**
     * Read and process ID3v1 tags
     *
     * @var bool
     */
    public $option_tag_id3v1 = true;

    /**
     * Read and process ID3v2 tags
     *
     * @var bool
     */
    public $option_tag_id3v2 = true;

    /**
     * Read and process Lyrics3 tags
     *
     * @var bool
     */
    public $option_tag_lyrics3 = true;

    /**
     * Read and process APE tags
     *
     * @var bool
     */
    public $option_tag_apetag = true;

    /**
     * Copy tags to root key 'tags' and encode to $this->encoding
     *
     * @var bool
     */
    public $option_tags_process = true;

    /**
     * Copy tags to root key 'tags_html' properly translated from various encodings to HTML entities
     *
     * @var bool
     */
    public $option_tags_html = true;

    // public: Optional tag/comment calculations

    /**
     * Calculate additional info such as bitrate, channel mode etc
     *
     * @var bool
     */
    public $option_extra_info = true;

    // public: Optional handling of embedded attachments (e.g. images)

    /**
     * defaults to true (ATTACHMENTS_INLINE) for backward compatibility
     *
     * @var bool
     */
    public $option_save_attachments = true;

    // public: Optional calculations

    /**
     * Get MD5 sum of data part - slow
     *
     * @var bool
     */
    public $option_md5_data = false;

    /**
     * Use MD5 of source file if availble - only FLAC and OptimFROG
     *
     * @var bool
     */
    public $option_md5_data_source = false;

    /**
     * Get SHA1 sum of data part - slow
     *
     * @var bool
     */
    public $option_sha1_data = false;

    /**
     * Check whether file is larger than 2GB and thus not supported by 32-bit PHP (null: auto-detect based on PHP_INT_MAX)
     */
    public $option_max_2gb_check = null;

    // public: Read buffer size in bytes

    /**
     * @var int
     */
    public $option_fread_buffer_size = 32768;

    // Public variables

    /**
     * Filename of file being analysed.
     *
     * @var string
     */
    public $filename;

    /**
     * Filepointer to file being analysed.
     *
     * @var resource
     */
    public $fp;

    /**
     * Result array.
     *
     * @var array
     */
    public $info = [];

    // Protected variables

    /**
     * @var string
     */
    protected $startup_error = '';

    /**
     * @var string
     */
    protected $startup_warning = '';

    /**
     * @var int
     */
    protected $memory_limit = 0;

    /**
     * @var string
     */
    public $tempdir;

    /**
     * $TempDir = '/something/else/';  // feel free to override temp dir here if it works better for your system
     *
     * @var string
     */
    protected static $TempDir;

    /**
     * @var string
     */
    protected static $IncludePath;

    /**
     * @var string
     */
    protected static $EnvironmentIsWindows;

    /**
     * @var string
     */
    protected static $HelperAppsDir;

    /**
     *
     */
    const VERSION = '1.9.4-20120530';

    /**
     *
     */
    const FREAD_BUFFER_SIZE = 32768;

    /**
     *
     */
    const ATTACHMENTS_NONE = false;

    /**
     *
     */
    const ATTACHMENTS_INLINE = true;

    /**
     * GetId3Core constructor.
     *
     * @throws DefaultException
     */
    public function __construct()
    {
        $this->tempdir = self::getTempDir();

        // Check for PHP version
        $required_php_version = '7.0';

        if ( version_compare( PHP_VERSION, $required_php_version, '<' ) )
        {
            $this->addStartupError( 'getID3() requires PHP v' . $required_php_version . ' or higher - you are running v' . PHP_VERSION );
        }

        // Check memory
        $this->setMemoryLimit( ini_get( 'memory_limit' ) );

        if ( preg_match( '#([0-9]+)M#i', $this->getMemoryLimit(), $matches ) )
        {
            // could be stored as "16M" rather than 16777216 for example
            $this->setMemoryLimit( $matches[1] * 1048576 );
        }
        elseif ( preg_match( '#([0-9]+)G#i', $this->getMemoryLimit(), $matches ) )
        { // The 'G' modifier is available since PHP 5.1.0
            // could be stored as "2G" rather than 2147483648 for example
            $this->setMemoryLimit( $matches[1] * 1073741824 );
        }

        if ( $this->getMemoryLimit() <= 0 )
        {
            // memory limits probably disabled
        }
        elseif ( $this->getMemoryLimit() <= 4194304 )
        {
            $this->addStartupError( 'PHP has less than 4MB available memory and will very likely run out. Increase memory_limit in php.ini' );
        }
        elseif ( $this->getMemoryLimit() <= 12582912 )
        {
            $this->addStartupWarning( 'PHP has less than 12MB available memory and might run out if all modules are loaded. Increase memory_limit in php.ini' );
        }

        if ( intval( ini_get( 'mbstring.func_overload' ) ) > 0 )
        {
            $this->warning( 'WARNING: php.ini contains "mbstring.func_overload = ' . ini_get( 'mbstring.func_overload' ) . '", this may break things.' );
        }

        if ( null === $this->getOptionMax2gbCheck() )
        {
            $this->setOptionMax2gbCheck( PHP_INT_MAX <= 2147483647 );
        }

        $this->setHelperAppsDir();

        // check for critical errors
        if ( $this->hasStartupError() )
        {
            throw new DefaultException( $this->getStartupError() );
        }
    }

    /**
     * Needed for Windows only:
     * Define locations of helper applications for Shorten, VorbisComment,
     * MetaFLAC as well as other helper functions such as head, tail, md5sum, etc
     * This path cannot contain spaces, but the below code will attempt to get
     * the 8.3-equivalent path automatically
     * IMPORTANT: This path must include the trailing slash
     */
    protected function setHelperAppsDir()
    {
        if ( self::$EnvironmentIsWindows && null === self::$HelperAppsDir )
        {
            $helperappsdir = self::$IncludePath . 'Resources' . DIRECTORY_SEPARATOR . 'helperapps'; // must not have any space in this path

            if ( !is_dir( $helperappsdir ) )
            {
                $this->addStartupWarning( '"' . $helperappsdir . '" cannot be defined as self::getHelperAppsDir() because it does not exist' );
            }
            elseif ( strpos( realpath( $helperappsdir ), ' ' ) !== false )
            {

                $DirPieces = explode( DIRECTORY_SEPARATOR, realpath( $helperappsdir ) );

                $path_so_far = [];

                foreach ( $DirPieces as $key => $value )
                {
                    if ( strpos( $value, ' ' ) !== false )
                    {
                        if ( !empty( $path_so_far ) )
                        {
                            $commandline = 'dir /x ' . escapeshellarg( implode( DIRECTORY_SEPARATOR, $path_so_far ) );
                            $dir_listing = `$commandline`;

                            $lines = explode( "\n", $dir_listing );

                            foreach ( $lines as $line )
                            {
                                $line = trim( $line );
                                if ( preg_match( '#^([0-9/]{10}) +([0-9:]{4,5}( [AP]M)?) +(<DIR>|[0-9,]+) +([^ ]{0,11}) +(.+)$#', $line, $matches ) )
                                {

                                    /** @noinspection PhpUnusedLocalVariableInspection */
                                    list( $dummy, $date, $time, $ampm, $filesize, $shortname, $filename ) = $matches;

                                    /** @noinspection HtmlDeprecatedTag */
                                    if ( ( strtoupper( $filesize ) == '<DIR>' ) && ( strtolower( $filename ) == strtolower( $value ) ) )
                                    {
                                        $value = $shortname;
                                    }

                                }
                            }

                        }
                        else
                        {
                            $this->addStartupWarning( 'self::getHelperAppsDir() must not have any spaces in it - use 8dot3 naming convention if neccesary. You can run "dir /x" from the commandline to see the correct 8.3-style names.' );
                        }
                    }

                    $path_so_far[] = $value;

                }

                $helperappsdir = implode( DIRECTORY_SEPARATOR, $path_so_far );
            }

            self::$HelperAppsDir = $helperappsdir . DIRECTORY_SEPARATOR;
        }
    }

    public static function getHelperAppsDir()
    {
        return self::$HelperAppsDir;
    }

    /**
     * @return string
     */
    public function version()
    {
        return self::VERSION;
    }

    /**
     * @return int
     */
    public function fread_buffer_size()
    {
        return $this->getOptionFreadBufferSize();
    }

    /**
     * public: setOption
     *
     * @param array $optArray
     *
     * @return bool
     */
    public function setOption( $optArray )
    {

        if ( !is_array( $optArray ) || empty( $optArray ) )
        {
            return false;
        }

        foreach ( $optArray as $opt => $val )
        {
            if ( false === isset( $this->$opt ) )
            {
                continue;
            }

            $this->$opt = $val;
        }

        return true;
    }

    /**
     * @param string $filename
     *
     * @return bool
     *
     * @throws DefaultException
     */
    public function openfile( $filename )
    {
        try
        {

            if ( $this->hasStartupError() )
            {
                throw new DefaultException( $this->getStartupError() );
            }

            if ( $this->hasStartupWarning() )
            {
                $this->warning( $this->getStartupWarning() );
            }

            // init result array and set parameters
            $this->setFilename( $filename );

            $this->info = [];

            $this->info['GETID3_VERSION']   = $this->version();
            $this->info['php_memory_limit'] = $this->getMemoryLimit();

            // remote files not supported
            if ( preg_match( '/^(ht|f)tp:\/\//', $filename ) )
            {
                throw new DefaultException( 'Remote files are not supported - please copy the file locally first' );
            }

            $filename = str_replace( '/', DIRECTORY_SEPARATOR, $filename );
            $filename = preg_replace( '#(.+)' . preg_quote( DIRECTORY_SEPARATOR ) . '{2,}#U', '\1' . DIRECTORY_SEPARATOR, $filename );

            // open local file
            if ( is_readable( $filename ) && is_file( $filename ) && ( $this->setFp( fopen( $filename, 'rb' ) ) ) )
            {
                // great
            }
            else
            {
                throw new DefaultException( 'Could not open "' . $filename . '" (does not exist, or is not a file)' );
            }

            $this->info['filesize'] = filesize( $filename );

            // set redundant parameters - might be needed in some include file

            $this->info['filename'] = basename( $filename );
            $this->info['filepath'] = str_replace( '\\', '/', realpath( dirname( $filename ) ) );

            $this->info['filenamepath'] = $this->info['filepath'] . '/' . $this->info['filename'];

            // option_max_2gb_check
            if ( $this->getOptionMax2gbCheck() )
            {

                // PHP (32-bit all, and 64-bit Windows) doesn't support integers larger than 2^31 (~2GB)
                // filesize() simply returns (filesize % (pow(2, 32)), no matter the actual filesize
                // ftell() returns 0 if seeking to the end is beyond the range of unsigned integer

                $fseek = fseek( $this->getFp(), 0, SEEK_END );

                if ( ( $fseek < 0 ) || ( ( $this->info['filesize'] != 0 ) && ( ftell( $this->getFp() ) == 0 ) ) || ( $this->info['filesize'] < 0 ) || ( ftell( $this->getFp() ) < 0 ) )
                {

                    $real_filesize = false;

                    if ( self::$EnvironmentIsWindows )
                    {
                        $commandline = 'dir /-C "' . str_replace( '/', DIRECTORY_SEPARATOR, $filename ) . '"';
                        $dir_output  = `$commandline`;

                        if ( preg_match( '#1 File\(s\)[ ]+([0-9]+) bytes#i', $dir_output, $matches ) )
                        {
                            $real_filesize = (float) $matches[1];
                        }
                    }
                    else
                    {
                        $commandline = 'ls -o -g -G --time-style=long-iso ' . escapeshellarg( $filename );
                        $dir_output  = `$commandline`;
                        if ( preg_match( '#([0-9]+) ([0-9]{4}-[0-9]{2}\-[0-9]{2} [0-9]{2}:[0-9]{2}) ' . str_replace( '#', '\\#', preg_quote( $filename ) ) . '$#', $dir_output, $matches ) )
                        {
                            $real_filesize = (float) $matches[1];
                        }
                    }

                    if ( false === $real_filesize )
                    {
                        unset( $this->info['filesize'] );
                        fclose( $this->getFp() );
                        throw new DefaultException( 'Unable to determine actual filesize. File is most likely larger than ' . round( PHP_INT_MAX / 1073741824 ) . 'GB and is not supported by PHP.' );
                    }
                    elseif ( Helper::intValueSupported( $real_filesize ) )
                    {
                        unset( $this->info['filesize'] );
                        fclose( $this->getFp() );
                        throw new DefaultException( 'PHP seems to think the file is larger than ' . round( PHP_INT_MAX / 1073741824 ) . 'GB, but filesystem reports it as ' . number_format( $real_filesize,
                                                                                                                                                                                             3 ) . 'GB, please report to info@getid3.org' );
                    }

                    $this->info['filesize'] = $real_filesize;
                    $this->error( 'File is larger than ' . round( PHP_INT_MAX / 1073741824 ) . 'GB (filesystem reports it as ' . number_format( $real_filesize, 3 ) . 'GB) and is not properly supported by PHP.' );
                }
            }

            // set more parameters
            $this->info['avdataoffset']        = 0;
            $this->info['avdataend']           = $this->info['filesize'];
            $this->info['fileformat']          = '';                // filled in later
            $this->info['audio']['dataformat'] = '';                // filled in later, unset if not used
            $this->info['video']['dataformat'] = '';                // filled in later, unset if not used
            $this->info['tags']                = [];           // filled in later, unset if not used
            $this->info['error']               = [];           // filled in later, unset if not used
            $this->info['warning']             = [];           // filled in later, unset if not used
            $this->info['comments']            = [];           // filled in later, unset if not used
            $this->info['encoding']            = $this->getEncoding();   // required by id3v2 and iso modules - can be unset at the end if desired

            return true;
        }
        catch ( DefaultException $e )
        {
            $this->error( $e->getMessage() );
        }

        return false;
    }

    /**
     * public: analyze file
     *
     * @param string $filename
     *
     * @return array
     *
     * @throws DefaultException
     */
    public function analyze( $filename )
    {
        try
        {

            if ( !$this->openfile( $filename ) )
            {
                return $this->info;
            }

            // Handle tags
            foreach ( [ 'id3v2' => 'id3v2', 'id3v1' => 'id3v1', 'apetag' => 'ape', 'lyrics3' => 'lyrics3' ] as $tag_name => $tag_key )
            {
                $option_tag = 'option_tag_' . $tag_name;

                if ( $this->$option_tag )
                {
                    try
                    {
                        $tag_class = '\arabcoders\getid3\Module\Tag\\' . ucfirst( $tag_name );
                        $tag       = new $tag_class( $this );
                        $tag->analyze();
                    }
                    catch ( DefaultException $e )
                    {
                        throw $e;
                    }
                }

            }

            if ( isset( $this->info['id3v2']['tag_offset_start'] ) )
            {
                $this->info['avdataoffset'] = max( $this->info['avdataoffset'], $this->info['id3v2']['tag_offset_end'] );
            }

            foreach ( [ 'id3v1' => 'id3v1', 'apetag' => 'ape', 'lyrics3' => 'lyrics3' ] as $tag_name => $tag_key )
            {
                if ( isset( $this->info[$tag_key]['tag_offset_start'] ) )
                {
                    $this->info['avdataend'] = min( $this->info['avdataend'], $this->info[$tag_key]['tag_offset_start'] );
                }
            }

            // ID3v2 detection (NOT parsing), even if ($this->option_tag_id3v2 == false) done to make fileformat easier
            if ( !$this->getOptionTagId3v2() )
            {
                fseek( $this->getFp(), 0, SEEK_SET );
                $header = fread( $this->getFp(), 10 );
                if ( ( substr( $header, 0, 3 ) == 'ID3' ) && ( strlen( $header ) == 10 ) )
                {
                    $this->info['id3v2']['header']       = true;
                    $this->info['id3v2']['majorversion'] = ord( $header{3} );
                    $this->info['id3v2']['minorversion'] = ord( $header{4} );
                    $this->info['avdataoffset'] += Helper::BigEndian2Int( substr( $header, 6, 4 ), 1 ) + 10; // length of ID3v2 tag in 10-byte header doesn't include 10-byte header length
                }
            }

            // read 32 kb file data
            fseek( $this->getFp(), $this->info['avdataoffset'], SEEK_SET );
            $formattest = fread( $this->getFp(), 32774 );

            // determine format
            $determined_format = $this->GetFileFormat( $formattest, $filename );

            // unable to determine file format
            if ( !$determined_format )
            {
                fclose( $this->getFp() );

                return $this->error( 'unable to determine file format' );
            }

            // check for illegal ID3 tags
            if ( isset( $determined_format['fail_id3'] ) && ( in_array( 'id3v1',
                                                                        $this->info['tags'] ) || in_array( 'id3v2',
                                                                                                           $this->info['tags'] ) )
            )
            {
                if ( $determined_format['fail_id3'] === 'ERROR' )
                {
                    fclose( $this->getFp() );

                    return $this->error( 'ID3 tags not allowed on this file type.' );
                }
                elseif ( $determined_format['fail_id3'] === 'WARNING' )
                {
                    $this->warning( 'ID3 tags not allowed on this file type.' );
                }
            }

            // check for illegal APE tags
            if ( isset( $determined_format['fail_ape'] ) && in_array( 'ape',
                                                                      $this->info['tags'] )
            )
            {
                if ( $determined_format['fail_ape'] === 'ERROR' )
                {
                    fclose( $this->getFp() );

                    return $this->error( 'APE tags not allowed on this file type.' );
                }
                elseif ( $determined_format['fail_ape'] === 'WARNING' )
                {
                    $this->warning( 'APE tags not allowed on this file type.' );
                }
            }

            // set mime type
            $this->info['mime_type'] = $determined_format['mime_type'];

            // supported format signature pattern detected, but module deleted
            if ( !class_exists( $determined_format['class'] ) )
            {
                fclose( $this->getFp() );

                return $this->error( 'Format not supported, module "' . $determined_format['include'] . '" was removed.' );
            }

            // module requires iconv support
            // Check encoding/iconv support
            if ( !empty( $determined_format['iconv_req'] ) && !function_exists( 'iconv' ) && !in_array( $this->getEncoding(),
                                                                                                        [ 'ISO-8859-1', 'UTF-8', 'UTF-16LE', 'UTF-16BE', 'UTF-16' ] )
            )
            {
                $errormessage = 'iconv() support is required for this module (' . $determined_format['include'] . ') for encodings other than ISO-8859-1, UTF-8, UTF-16LE, UTF16-BE, UTF-16. ';
                if ( self::$EnvironmentIsWindows )
                {
                    $errormessage .= 'PHP does not have iconv() support. Please enable php_iconv.dll in php.ini, and copy iconv.dll from c:/php/dlls to c:/windows/system32';
                }
                else
                {
                    $errormessage .= 'PHP is not compiled with iconv() support. Please recompile with the --with-iconv switch';
                }

                return $this->error( $errormessage );
            }

            // instantiate module class
            $class_name = '\\arabcoders\\getid3\\Module\\' . Helper::toCamelCase( $determined_format['group'], '-', true ) . '\\' . ucfirst( $determined_format['module'] );
            if ( !class_exists( $class_name ) )
            {
                return $this->error( 'Format not supported, module "' . $determined_format['include'] . '" is corrupt.' );
            }
            //if (isset($determined_format['option'])) {
            //	//$class = new $class_name($this->fp, $this->info, $determined_format['option']);
            //} else {
            //$class = new $class_name($this->fp, $this->info);
            $class = new $class_name( $this );
            //}

            if ( !empty( $determined_format['set_inline_attachments'] ) )
            {
                $class->inline_attachments = $this->option_save_attachments;
            }

            $class->analyze();

            unset( $class );

            // close file
            fclose( $this->getFp() );

            // process all tags - copy to 'tags' and convert charsets
            if ( $this->getOptionTagsProcess() )
            {
                $this->HandleAllTags();
            }

            // perform more calculations
            if ( $this->getOptionExtraInfo() )
            {
                $this->ChannelsBitratePlaytimeCalculations();
                $this->CalculateCompressionRatioVideo();
                $this->CalculateCompressionRatioAudio();
                $this->CalculateReplayGain();
                $this->ProcessAudioStreams();
            }

            // get the MD5 sum of the audio/video portion of the file - without ID3/APE/Lyrics3/etc header/footer tags
            if ( $this->getOptionMD5Data() )
            {
                // do not cald md5_data if md5_data_source is present - set by flac only - future MPC/SV8 too
                if ( !$this->getOptionMD5DataDource() || empty( $this->info['md5_data_source'] ) )
                {
                    $this->getHashdata( 'md5' );
                }
            }

            // get the SHA1 sum of the audio/video portion of the file - without ID3/APE/Lyrics3/etc header/footer tags
            if ( $this->getOptionSha1Data() )
            {
                $this->getHashdata( 'sha1' );
            }

            // remove undesired keys
            $this->CleanUp();
        }
        catch ( \Exception $e )
        {
            $this->error( 'Caught exception: ' . $e->getMessage() );
        }

        // return info array
        return $this->info;
    }

    /**
     * private: error handling
     *
     * @param string $message
     *
     * @return array
     */
    private function error( $message )
    {
        $this->CleanUp();
        if ( !isset( $this->info['error'] ) )
        {
            $this->info['error'] = [];
        }
        $this->info['error'][] = $message;

        return $this->info;
    }

    /**
     * private: warning handling
     *
     * @param string $message
     *
     * @return bool
     */
    public function warning( $message )
    {
        $this->info['warning'][] = $message;

        return true;
    }

    /**
     * private: CleanUp
     *
     * @return bool
     */
    private function CleanUp()
    {
        // remove possible empty keys
        $AVpossibleEmptyKeys = [ 'dataformat', 'bits_per_sample', 'encoder_options', 'streams', 'bitrate' ];

        foreach ( $AVpossibleEmptyKeys as $dummy => $key )
        {
            if ( empty( $this->info['audio'][$key] ) && isset( $this->info['audio'][$key] ) )
            {
                unset( $this->info['audio'][$key] );
            }
            if ( empty( $this->info['video'][$key] ) && isset( $this->info['video'][$key] ) )
            {
                unset( $this->info['video'][$key] );
            }
        }

        // remove empty root keys
        if ( !empty( $this->info ) )
        {
            foreach ( $this->info as $key => $value )
            {
                if ( empty( $this->info[$key] ) && ( $this->info[$key] !== 0 ) && ( $this->info[$key] !== '0' ) )
                {
                    unset( $this->info[$key] );
                }
            }
        }

        // remove meaningless entries from unknown-format files
        if ( empty( $this->info['fileformat'] ) )
        {
            if ( isset( $this->info['avdataoffset'] ) )
            {
                unset( $this->info['avdataoffset'] );
            }
            if ( isset( $this->info['avdataend'] ) )
            {
                unset( $this->info['avdataend'] );
            }
        }

        // remove possible duplicated identical entries
        if ( !empty( $this->info['error'] ) )
        {
            $this->info['error'] = array_values( array_unique( $this->info['error'] ) );
        }
        if ( !empty( $this->info['warning'] ) )
        {
            $this->info['warning'] = array_values( array_unique( $this->info['warning'] ) );
        }

        // remove "global variable" type keys
        unset( $this->info['php_memory_limit'] );

        return true;
    }

    /**
     * @staticvar array $format_info
     *
     * @return array array containing information about all supported formats
     */
    public function GetFileFormatArray()
    {
        static $format_info = [];
        if ( empty( $format_info ) )
        {
            $format_info = [
                // Audio formats
                // AC-3   - audio      - Dolby AC-3 / Dolby Digital
                'ac3'       => [
                    'pattern'   => '^\x0B\x77',
                    'group'     => 'audio',
                    'module'    => 'ac3',
                    'mime_type' => 'audio/ac3',
                ],
                // AAC  - audio       - Advanced Audio Coding (AAC) - ADIF format
                'adif'      => [
                    'pattern'   => '^ADIF',
                    'group'     => 'audio',
                    'module'    => 'aac',
                    'mime_type' => 'application/octet-stream',
                    'fail_ape'  => 'WARNING',
                ],
                // AA   - audio       - Audible Audiobook
                'aa'        => [
                    'pattern'   => '^.{4}\x57\x90\x75\x36',
                    'group'     => 'audio',
                    'module'    => 'aa',
                    'mime_type' => 'audio/audible',
                ],
                // AAC  - audio       - Advanced Audio Coding (AAC) - ADTS format (very similar to MP3)
                'adts'      => [
                    'pattern'   => '^\xFF[\xF0-\xF1\xF8-\xF9]',
                    'group'     => 'audio',
                    'module'    => 'aac',
                    'mime_type' => 'application/octet-stream',
                    'fail_ape'  => 'WARNING',
                ],
                // AU   - audio       - NeXT/Sun AUdio (AU)
                'au'        => [
                    'pattern'   => '^\.snd',
                    'group'     => 'audio',
                    'module'    => 'au',
                    'mime_type' => 'audio/basic',
                ],
                // AVR  - audio       - Audio Visual Research
                'avr'       => [
                    'pattern'   => '^2BIT',
                    'group'     => 'audio',
                    'module'    => 'avr',
                    'mime_type' => 'application/octet-stream',
                ],
                // BONK - audio       - Bonk v0.9+
                'bonk'      => [
                    'pattern'   => '^\x00(BONK|INFO|META| ID3)',
                    'group'     => 'audio',
                    'module'    => 'bonk',
                    'mime_type' => 'audio/xmms-bonk',
                ],
                // DSS  - audio       - Digital Speech Standard
                'dss'       => [
                    'pattern'   => '^[\x02-\x03]dss',
                    'group'     => 'audio',
                    'module'    => 'dss',
                    'mime_type' => 'application/octet-stream',
                ],
                // DTS  - audio       - Dolby Theatre System
                'dts'       => [
                    'pattern'   => '^\x7F\xFE\x80\x01',
                    'group'     => 'audio',
                    'module'    => 'dts',
                    'mime_type' => 'audio/dts',
                ],
                // FLAC - audio       - Free Lossless Audio Codec
                'flac'      => [
                    'pattern'                => '^fLaC',
                    'group'                  => 'audio',
                    'module'                 => 'flac',
                    'mime_type'              => 'audio/x-flac',
                    'set_inline_attachments' => true,
                ],
                // LA   - audio       - Lossless Audio (LA)
                'la'        => [
                    'pattern'   => '^LA0[2-4]',
                    'group'     => 'audio',
                    'module'    => 'la',
                    'mime_type' => 'application/octet-stream',
                ],
                // LPAC - audio       - Lossless Predictive Audio Compression (LPAC)
                'lpac'      => [
                    'pattern'   => '^LPAC',
                    'group'     => 'audio',
                    'module'    => 'lpac',
                    'mime_type' => 'application/octet-stream',
                ],
                // MIDI - audio       - MIDI (Musical Instrument Digital Interface)
                'midi'      => [
                    'pattern'   => '^MThd',
                    'group'     => 'audio',
                    'module'    => 'midi',
                    'mime_type' => 'audio/midi',
                ],
                // MAC  - audio       - Monkey's Audio Compressor
                'mac'       => [
                    'pattern'   => '^MAC ',
                    'group'     => 'audio',
                    'module'    => 'monkey',
                    'mime_type' => 'application/octet-stream',
                ],
                // has been known to produce false matches in random files (e.g. JPEGs), leave out until more precise matching available
                //				// MOD  - audio       - MODule (assorted sub-formats)
                //				'mod'  => array(
                //							'pattern'   => '^.{1080}(M\\.K\\.|M!K!|FLT4|FLT8|[5-9]CHN|[1-3][0-9]CH)',
                //							'group'     => 'audio',
                //							'module'    => 'mod',
                //							'option'    => 'mod',
                //							'mime_type' => 'audio/mod',
                //						),
                // MOD  - audio       - MODule (Impulse Tracker)
                'it'        => [
                    'pattern'   => '^IMPM',
                    'group'     => 'audio',
                    'module'    => 'mod',
                    //'option'    => 'it',
                    'mime_type' => 'audio/it',
                ],
                // MOD  - audio       - MODule (eXtended Module, various sub-formats)
                'xm'        => [
                    'pattern'   => '^Extended Module',
                    'group'     => 'audio',
                    'module'    => 'mod',
                    //'option'    => 'xm',
                    'mime_type' => 'audio/xm',
                ],
                // MOD  - audio       - MODule (ScreamTracker)
                's3m'       => [
                    'pattern'   => '^.{44}SCRM',
                    'group'     => 'audio',
                    'module'    => 'mod',
                    //'option'    => 's3m',
                    'mime_type' => 'audio/s3m',
                ],
                // MPC  - audio       - Musepack / MPEGplus
                'mpc'       => [
                    'pattern'   => '^(MPCK|MP\+|[\x00\x01\x10\x11\x40\x41\x50\x51\x80\x81\x90\x91\xC0\xC1\xD0\xD1][\x20-37][\x00\x20\x40\x60\x80\xA0\xC0\xE0])',
                    'group'     => 'audio',
                    'module'    => 'mpc',
                    'mime_type' => 'audio/x-musepack',
                ],
                // MP3  - audio       - MPEG-audio Layer 3 (very similar to AAC-ADTS)
                'mp3'       => [
                    'pattern'   => '^\xFF[\xE2-\xE7\xF2-\xF7\xFA-\xFF][\x00-\x0B\x10-\x1B\x20-\x2B\x30-\x3B\x40-\x4B\x50-\x5B\x60-\x6B\x70-\x7B\x80-\x8B\x90-\x9B\xA0-\xAB\xB0-\xBB\xC0-\xCB\xD0-\xDB\xE0-\xEB\xF0-\xFB]',
                    'group'     => 'audio',
                    'module'    => 'mp3',
                    'mime_type' => 'audio/mpeg',
                ],
                // OFR  - audio       - OptimFROG
                'ofr'       => [
                    'pattern'   => '^(\*RIFF|OFR)',
                    'group'     => 'audio',
                    'module'    => 'optimfrog',
                    'mime_type' => 'application/octet-stream',
                ],
                // RKAU - audio       - RKive AUdio compressor
                'rkau'      => [
                    'pattern'   => '^RKA',
                    'group'     => 'audio',
                    'module'    => 'rkau',
                    'mime_type' => 'application/octet-stream',
                ],
                // SHN  - audio       - Shorten
                'shn'       => [
                    'pattern'   => '^ajkg',
                    'group'     => 'audio',
                    'module'    => 'shorten',
                    'mime_type' => 'audio/xmms-shn',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // TTA  - audio       - TTA Lossless Audio Compressor (http://tta.corecodec.org)
                'tta'       => [
                    'pattern'   => '^TTA', // could also be '^TTA(\x01|\x02|\x03|2|1)'
                    'group'     => 'audio',
                    'module'    => 'tta',
                    'mime_type' => 'application/octet-stream',
                ],
                // VOC  - audio       - Creative Voice (VOC)
                'voc'       => [
                    'pattern'   => '^Creative Voice File',
                    'group'     => 'audio',
                    'module'    => 'voc',
                    'mime_type' => 'audio/voc',
                ],
                // VQF  - audio       - transform-domain weighted interleave Vector Quantization Format (VQF)
                'vqf'       => [
                    'pattern'   => '^TWIN',
                    'group'     => 'audio',
                    'module'    => 'vqf',
                    'mime_type' => 'application/octet-stream',
                ],
                // WV  - audio        - WavPack (v4.0+)
                'wv'        => [
                    'pattern'   => '^wvpk',
                    'group'     => 'audio',
                    'module'    => 'wavpack',
                    'mime_type' => 'application/octet-stream',
                ],
                // Audio-Video formats
                // ASF  - audio/video - Advanced Streaming Format, Windows Media Video, Windows Media Audio
                'asf'       => [
                    'pattern'   => '^\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C',
                    'group'     => 'audio-video',
                    'module'    => 'asf',
                    'mime_type' => 'video/x-ms-asf',
                    'iconv_req' => false,
                ],
                // BINK - audio/video - Bink / Smacker
                'bink'      => [
                    'pattern'   => '^(BIK|SMK)',
                    'group'     => 'audio-video',
                    'module'    => 'bink',
                    'mime_type' => 'application/octet-stream',
                ],
                // FLV  - audio/video - FLash Video
                'flv'       => [
                    'pattern'   => '^FLV\x01',
                    'group'     => 'audio-video',
                    'module'    => 'flv',
                    'mime_type' => 'video/x-flv',
                ],
                // MKAV - audio/video - Mastroka
                'matroska'  => [
                    'pattern'                => '^\x1A\x45\xDF\xA3',
                    'group'                  => 'audio-video',
                    'module'                 => 'matroska',
                    'mime_type'              => 'video/x-matroska', // may also be audio/x-matroska
                    'set_inline_attachments' => true,
                ],
                // MPEG - audio/video - MPEG (Moving Pictures Experts Group)
                'mpeg'      => [
                    'pattern'   => '^\x00\x00\x01(\xBA|\xB3)',
                    'group'     => 'audio-video',
                    'module'    => 'mpeg',
                    'mime_type' => 'video/mpeg',
                ],
                // NSV  - audio/video - Nullsoft Streaming Video (NSV)
                'nsv'       => [
                    'pattern'   => '^NSV[sf]',
                    'group'     => 'audio-video',
                    'module'    => 'nsv',
                    'mime_type' => 'application/octet-stream',
                ],
                // Ogg  - audio/video - Ogg (Ogg-Vorbis, Ogg-FLAC, Speex, Ogg-Theora(*), Ogg-Tarkin(*))
                'ogg'       => [
                    'pattern'                => '^OggS',
                    'group'                  => 'audio',
                    'module'                 => 'ogg',
                    'mime_type'              => 'application/ogg',
                    'fail_id3'               => 'WARNING',
                    'fail_ape'               => 'WARNING',
                    'set_inline_attachments' => true,
                ],
                // QT   - audio/video - Quicktime
                'quicktime' => [
                    'pattern'   => '^.{4}(cmov|free|ftyp|mdat|moov|pnot|skip|wide)',
                    'group'     => 'audio-video',
                    'module'    => 'quicktime',
                    'mime_type' => 'video/quicktime',
                ],
                // RIFF - audio/video - Resource Interchange File Format (RIFF) / WAV / AVI / CD-audio / SDSS = renamed variant used by SmartSound QuickTracks (www.smartsound.com) / FORM = Audio Interchange File Format (AIFF)
                'riff'      => [
                    'pattern'   => '^(RIFF|SDSS|FORM)',
                    'group'     => 'audio-video',
                    'module'    => 'riff',
                    'mime_type' => 'audio/x-wave',
                    'fail_ape'  => 'WARNING',
                ],
                // Real - audio/video - RealAudio, RealVideo
                'real'      => [
                    'pattern'   => '^(\\.RMF|\\.ra)',
                    'group'     => 'audio-video',
                    'module'    => 'real',
                    'mime_type' => 'audio/x-realaudio',
                ],
                // SWF - audio/video - ShockWave Flash
                'swf'       => [
                    'pattern'   => '^(F|C)WS',
                    'group'     => 'audio-video',
                    'module'    => 'swf',
                    'mime_type' => 'application/x-shockwave-flash',
                ],
                // TS - audio/video - MPEG-2 Transport Stream
                'ts'        => [
                    'pattern'   => '^\x47',
                    'group'     => 'audio-video',
                    'module'    => 'ts',
                    'mime_type' => 'video/MP2T',
                ],
                // Still-Image formats
                // BMP  - still image - Bitmap (Windows, OS/2; uncompressed, RLE8, RLE4)
                'bmp'       => [
                    'pattern'   => '^BM',
                    'group'     => 'graphic',
                    'module'    => 'bmp',
                    'mime_type' => 'image/bmp',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // GIF  - still image - Graphics Interchange Format
                'gif'       => [
                    'pattern'   => '^GIF',
                    'group'     => 'graphic',
                    'module'    => 'gif',
                    'mime_type' => 'image/gif',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // JPEG - still image - Joint Photographic Experts Group (JPEG)
                'jpg'       => [
                    'pattern'   => '^\xFF\xD8\xFF',
                    'group'     => 'graphic',
                    'module'    => 'jpg',
                    'mime_type' => 'image/jpeg',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // PCD  - still image - Kodak Photo CD
                'pcd'       => [
                    'pattern'   => '^.{2048}PCD_IPI\x00',
                    'group'     => 'graphic',
                    'module'    => 'pcd',
                    'mime_type' => 'image/x-photo-cd',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // PNG  - still image - Portable Network Graphics (PNG)
                'png'       => [
                    'pattern'   => '^\x89\x50\x4E\x47\x0D\x0A\x1A\x0A',
                    'group'     => 'graphic',
                    'module'    => 'png',
                    'mime_type' => 'image/png',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // SVG  - still image - Scalable Vector Graphics (SVG)
                'svg'       => [
                    'pattern'   => '(<!DOCTYPE svg PUBLIC |xmlns="http:\/\/www\.w3\.org\/2000\/svg")',
                    'group'     => 'graphic',
                    'module'    => 'svg',
                    'mime_type' => 'image/svg+xml',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // TIFF - still image - Tagged Information File Format (TIFF)
                'tiff'      => [
                    'pattern'   => '^(II\x2A\x00|MM\x00\x2A)',
                    'group'     => 'graphic',
                    'module'    => 'tiff',
                    'mime_type' => 'image/tiff',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // EFAX - still image - eFax (TIFF derivative)
                'efax'      => [
                    'pattern'   => '^\xDC\xFE',
                    'group'     => 'graphic',
                    'module'    => 'efax',
                    'mime_type' => 'image/efax',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // Data formats
                // ISO  - data        - International Standards Organization (ISO) CD-ROM Image
                'iso'       => [
                    'pattern'   => '^.{32769}CD001',
                    'group'     => 'misc',
                    'module'    => 'iso',
                    'mime_type' => 'application/octet-stream',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                    'iconv_req' => false,
                ],
                // RAR  - data        - RAR compressed data
                'rar'       => [
                    'pattern'   => '^Rar\!',
                    'group'     => 'archive',
                    'module'    => 'rar',
                    'mime_type' => 'application/octet-stream',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // SZIP - audio/data  - SZIP compressed data
                'szip'      => [
                    'pattern'   => '^SZ\x0A\x04',
                    'group'     => 'archive',
                    'module'    => 'szip',
                    'mime_type' => 'application/octet-stream',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // TAR  - data        - TAR compressed data
                'tar'       => [
                    'pattern'   => '^.{100}[0-9\x20]{7}\x00[0-9\x20]{7}\x00[0-9\x20]{7}\x00[0-9\x20\x00]{12}[0-9\x20\x00]{12}',
                    'group'     => 'archive',
                    'module'    => 'tar',
                    'mime_type' => 'application/x-tar',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // GZIP  - data        - GZIP compressed data
                'gz'        => [
                    'pattern'   => '^\x1F\x8B\x08',
                    'group'     => 'archive',
                    'module'    => 'gzip',
                    'mime_type' => 'application/x-gzip',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // ZIP  - data         - ZIP compressed data
                'zip'       => [
                    'pattern'   => '^PK\x03\x04',
                    'group'     => 'archive',
                    'module'    => 'zip',
                    'mime_type' => 'application/zip',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // Misc other formats
                // PAR2 - data        - Parity Volume Set Specification 2.0
                'par2'      => [
                    'pattern'   => '^PAR2\x00PKT',
                    'group'     => 'misc',
                    'module'    => 'par2',
                    'mime_type' => 'application/octet-stream',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // PDF  - data        - Portable Document Format
                'pdf'       => [
                    'pattern'   => '^\x25PDF',
                    'group'     => 'misc',
                    'module'    => 'pdf',
                    'mime_type' => 'application/pdf',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // MSOFFICE  - data   - ZIP compressed data
                'msoffice'  => [
                    'pattern'   => '^\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1', // D0CF11E == DOCFILE == Microsoft Office Document
                    'group'     => 'misc',
                    'module'    => 'msoffice',
                    'mime_type' => 'application/octet-stream',
                    'fail_id3'  => 'ERROR',
                    'fail_ape'  => 'ERROR',
                ],
                // CUE  - data       - CUEsheet (index to single-file disc images)
                'cue'       => [
                    'pattern'   => '', // empty pattern means cannot be automatically detected, will fall through all other formats and match based on filename and very basic file contents
                    'group'     => 'misc',
                    'module'    => 'cue',
                    'mime_type' => 'application/octet-stream',
                ],
            ];
        }

        return $format_info;
    }

    /**
     * @param string $filedata
     * @param string $filename
     *
     * @return string|bool
     */
    public function GetFileFormat( &$filedata, $filename = '' )
    {
        // this function will determine the format of a file based on usually
        // the first 2-4 bytes of the file (8 bytes for PNG, 16 bytes for JPG,
        // and in the case of ISO CD image, 6 bytes offset 32kb from the start
        // of the file).
        // Identify file format - loop through $format_info and detect with reg expr
        $GetFileFormatArray = $this->GetFileFormatArray();
        foreach ( $GetFileFormatArray as $format_name => $info )
        {
            // The /s switch on preg_match() forces preg_match() NOT to treat
            // newline (0x0A) characters as special chars but do a binary match
            if ( !empty( $info['pattern'] )
                && preg_match( '#' . $info['pattern'] . '#s', $filedata )
            )
            {
                $info['class']   = '\\arabcoders\\getid3\\Module\\' . Helper::toCamelCase( $info['group'], '-', true ) . '\\' . ucfirst( $info['module'] );
                $info['include'] = str_replace( '\\', DIRECTORY_SEPARATOR, $info['class'] ) . '.php';

                return $info;
            }
        }

        if ( preg_match( '#\.mp[123a]$#i', $filename ) )
        {
            // Too many mp3 encoders on the market put gabage in front of mpeg files
            // use assume format on these if format detection failed
            $info            = $GetFileFormatArray['mp3'];
            $info['class']   = '\\arabcoders\\getid3\\Module\\' . Helper::toCamelCase( $info['group'], '-', true ) . '\\' . ucfirst( $info['module'] );
            $info['include'] = str_replace( '\\', DIRECTORY_SEPARATOR, $info['class'] ) . '.php';

            return $info;
        }
        elseif ( preg_match( '/\.cue$/i', $filename ) && preg_match( '#FILE "[^"]+" (BINARY|MOTOROLA|AIFF|WAVE|MP3)#',
                                                                     $filedata )
        )
        {
            // there's not really a useful consistent "magic" at the beginning of .cue files to identify them
            // so until I think of something better, just go by filename if all other format checks fail
            // and verify there's at least one instance of "TRACK xx AUDIO" in the file
            $info            = $GetFileFormatArray['cue'];
            $info['class']   = '\\arabcoders\\getid3\\Module\\' . Helper::toCamelCase( $info['group'], '-', true ) . '\\' . ucfirst( $info['module'] );
            $info['include'] = str_replace( '\\', DIRECTORY_SEPARATOR, $info['class'] ) . '.php';

            return $info;
        }

        return false;
    }

    /**
     * @param array  $array
     * @param string $encoding
     *
     * @return void type converts array to $encoding charset from $this->encoding
     */
    public function CharConvert( &$array, $encoding )
    {

        // identical encoding - end here
        if ( $encoding == $this->getEncoding() )
        {
            return;
        }

        // loop thru array
        foreach ( $array as $key => $value )
        {

            // go recursive
            if ( is_array( $value ) )
            {
                $this->CharConvert( $array[$key], $encoding );
            }

            // convert string
            elseif ( is_string( $value ) )
            {
                $array[$key] = trim( Helper::iconv_fallback( $encoding,
                                                             $this->getEncoding(),
                                                             $value ) );
            }
        }
    }

    /**
     * @staticvar array $tags
     *
     * @return bool
     */
    public function HandleAllTags()
    {

        // key name => array (tag name, character encoding)
        static $tags;
        if ( empty( $tags ) )
        {
            $tags = [
                'asf'       => [ 'asf', 'UTF-16LE' ],
                'midi'      => [ 'midi', 'ISO-8859-1' ],
                'nsv'       => [ 'nsv', 'ISO-8859-1' ],
                'ogg'       => [ 'vorbiscomment', 'UTF-8' ],
                'png'       => [ 'png', 'UTF-8' ],
                'tiff'      => [ 'tiff', 'ISO-8859-1' ],
                'quicktime' => [ 'quicktime', 'UTF-8' ],
                'real'      => [ 'real', 'ISO-8859-1' ],
                'vqf'       => [ 'vqf', 'ISO-8859-1' ],
                'zip'       => [ 'zip', 'ISO-8859-1' ],
                'riff'      => [ 'riff', 'ISO-8859-1' ],
                'lyrics3'   => [ 'lyrics3', 'ISO-8859-1' ],
                'id3v1'     => [ 'id3v1', $this->encoding_id3v1 ],
                'id3v2'     => [ 'id3v2', 'UTF-8' ], // not according to the specs (every frame can have a different encoding), but GetId3Core() force-converts all encodings to UTF-8
                'ape'       => [ 'ape', 'UTF-8' ],
                'cue'       => [ 'cue', 'ISO-8859-1' ],
                'matroska'  => [ 'matroska', 'UTF-8' ],
                'flac'      => [ 'vorbiscomment', 'UTF-8' ],
            ];
        }

        // loop through comments array
        foreach ( $tags as $comment_name => $tagname_encoding_array )
        {
            list( $tag_name, $encoding ) = $tagname_encoding_array;

            // fill in default encoding type if not already present
            if ( isset( $this->info[$comment_name] ) && !isset( $this->info[$comment_name]['encoding'] ) )
            {
                $this->info[$comment_name]['encoding'] = $encoding;
            }

            // copy comments if key name set
            if ( !empty( $this->info[$comment_name]['comments'] ) )
            {
                foreach ( $this->info[$comment_name]['comments'] as $tag_key => $valuearray )
                {
                    foreach ( $valuearray as $key => $value )
                    {
                        if ( is_string( $value ) )
                        {
                            $value = trim( $value, " \r\n\t" ); // do not trim nulls from $value!! Unicode characters will get mangled if trailing nulls are removed!
                        }
                        if ( $value )
                        {
                            $this->info['tags'][trim( $tag_name )][trim( $tag_key )][] = $value;
                        }
                    }
                    if ( $tag_key == 'picture' )
                    {
                        unset( $this->info[$comment_name]['comments'][$tag_key] );
                    }
                }

                if ( !isset( $this->info['tags'][$tag_name] ) )
                {
                    // comments are set but contain nothing but empty strings, so skip
                    continue;
                }

                if ( $this->getOptionTagsHtml() )
                {
                    foreach ( $this->info['tags'][$tag_name] as $tag_key => $valuearray )
                    {
                        foreach ( $valuearray as $key => $value )
                        {
                            if ( is_string( $value ) )
                            {
                                //$this->info['tags_html'][$tag_name][$tag_key][$key] = GetId3_lib::MultiByteCharString2HTML($value, $encoding);
                                $this->info['tags_html'][$tag_name][$tag_key][$key] = str_replace( '&#0;',
                                                                                                   '',
                                                                                                   trim( Helper::MultiByteCharString2HTML( $value,
                                                                                                                                           $encoding ) ) );
                            }
                            else
                            {
                                $this->info['tags_html'][$tag_name][$tag_key][$key] = $value;
                            }
                        }
                    }
                }

                $this->CharConvert( $this->info['tags'][$tag_name], $encoding );           // only copy gets converted!
            }
        }

        // pictures can take up a lot of space, and we don't need multiple copies of them
        // let there be a single copy in [comments][picture], and not elsewhere
        if ( !empty( $this->info['tags'] ) )
        {
            $unset_keys = [ 'tags', 'tags_html' ];
            foreach ( $this->info['tags'] as $tagtype => $tagarray )
            {
                foreach ( $tagarray as $tagname => $tagdata )
                {
                    if ( $tagname == 'picture' )
                    {
                        foreach ( $tagdata as $key => $tagarray )
                        {
                            $this->info['comments']['picture'][] = $tagarray;
                            if ( isset( $tagarray['data'] ) && isset( $tagarray['image_mime'] ) )
                            {
                                if ( isset( $this->info['tags'][$tagtype][$tagname][$key] ) )
                                {
                                    unset( $this->info['tags'][$tagtype][$tagname][$key] );
                                }
                                if ( isset( $this->info['tags_html'][$tagtype][$tagname][$key] ) )
                                {
                                    unset( $this->info['tags_html'][$tagtype][$tagname][$key] );
                                }
                            }
                        }
                    }
                }
                foreach ( $unset_keys as $unset_key )
                {
                    // remove possible empty keys from (e.g. [tags][id3v2][picture])
                    if ( empty( $this->info[$unset_key][$tagtype]['picture'] ) )
                    {
                        unset( $this->info[$unset_key][$tagtype]['picture'] );
                    }
                    if ( empty( $this->info[$unset_key][$tagtype] ) )
                    {
                        unset( $this->info[$unset_key][$tagtype] );
                    }
                    if ( empty( $this->info[$unset_key] ) )
                    {
                        unset( $this->info[$unset_key] );
                    }
                }
                // remove duplicate copy of picture data from (e.g. [id3v2][comments][picture])
                if ( isset( $this->info[$tagtype]['comments']['picture'] ) )
                {
                    unset( $this->info[$tagtype]['comments']['picture'] );
                }
                if ( empty( $this->info[$tagtype]['comments'] ) )
                {
                    unset( $this->info[$tagtype]['comments'] );
                }
                if ( empty( $this->info[$tagtype] ) )
                {
                    unset( $this->info[$tagtype] );
                }
            }
        }

        return true;
    }

    /**
     * @param string $algorithm
     *
     * @return bool|string
     */
    public function getHashdata( $algorithm )
    {
        switch ( $algorithm )
        {
            case 'md5':
            case 'sha1':
                break;

            default:
                return $this->error( 'bad algorithm "' . $algorithm . '" in getHashdata()' );
                break;
        }

        if ( !empty( $this->info['fileformat'] ) && !empty( $this->info['dataformat'] ) && ( $this->info['fileformat'] == 'ogg' ) && ( $this->info['audio']['dataformat'] == 'vorbis' ) )
        {

            // We cannot get an identical md5_data value for Ogg files where the comments
            // span more than 1 Ogg page (compared to the same audio data with smaller
            // comments) using the normal GetId3Core() method of MD5'ing the data between the
            // end of the comments and the end of the file (minus any trailing tags),
            // because the page sequence numbers of the pages that the audio data is on
            // do not match. Under normal circumstances, where comments are smaller than
            // the nominal 4-8kB page size, then this is not a problem, but if there are
            // very large comments, the only way around it is to strip off the comment
            // tags with vorbiscomment and MD5 that file.
            // This procedure must be applied to ALL Ogg files, not just the ones with
            // comments larger than 1 page, because the below method simply MD5's the
            // whole file with the comments stripped, not just the portion after the
            // comments block (which is the standard GetId3Core() method.
            // The above-mentioned problem of comments spanning multiple pages and changing
            // page sequence numbers likely happens for OggSpeex and OggFLAC as well, but
            // currently vorbiscomment only works on OggVorbis files.

            if ( preg_match( '#(1|ON)#i', ini_get( 'safe_mode' ) ) )
            {
                $this->warning( 'Failed making system call to vorbiscomment.exe - ' . $algorithm . '_data is incorrect - error returned: PHP running in Safe Mode (backtick operator not available)' );
                $this->info[$algorithm . '_data'] = false;
            }
            else
            {

                // Prevent user from aborting script
                $old_abort = ignore_user_abort( true );

                // Create empty file
                $empty = tempnam( self::getTempDir(), 'getID3' );
                touch( $empty );

                // Use vorbiscomment to make temp file without comments
                $temp = tempnam( self::getTempDir(), 'getID3' );
                $file = $this->info['filenamepath'];

                if ( self::$EnvironmentIsWindows )
                {
                    if ( file_exists( self::getHelperAppsDir() . 'vorbiscomment.exe' ) )
                    {
                        $commandline        = '"' . self::getHelperAppsDir() . 'vorbiscomment.exe" -w -c "' . $empty . '" "' . $file . '" "' . $temp . '"';
                        $VorbisCommentError = `$commandline`;
                    }
                    else
                    {
                        $VorbisCommentError = 'vorbiscomment.exe not found in ' . self::getHelperAppsDir();
                    }
                }
                else
                {
                    $commandline        = 'vorbiscomment -w -c ' . escapeshellarg( $empty ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $temp ) . ' 2>&1';
                    $VorbisCommentError = `$commandline`;
                }

                if ( !empty( $VorbisCommentError ) )
                {
                    $this->info['warning'][]          = 'Failed making system call to vorbiscomment(.exe) - ' . $algorithm . '_data will be incorrect. If vorbiscomment is unavailable, please download from http://www.vorbis.com/download.psp and put in the GetId3Core() directory. Error returned: ' . $VorbisCommentError;
                    $this->info[$algorithm . '_data'] = false;
                }
                else
                {

                    // Get hash of newly created file
                    switch ( $algorithm )
                    {
                        case 'md5':
                            $this->info[$algorithm . '_data'] = md5_file( $temp );
                            break;

                        case 'sha1':
                            $this->info[$algorithm . '_data'] = sha1_file( $temp );
                            break;
                    }
                }

                // Clean up
                unlink( $empty );
                unlink( $temp );

                // Reset abort setting
                ignore_user_abort( $old_abort );
            }
        }
        else
        {
            if ( !empty( $this->info['avdataoffset'] ) || ( isset( $this->info['avdataend'] ) && ( $this->info['avdataend'] < $this->info['filesize'] ) ) )
            {

                // get hash from part of file
                $this->info[$algorithm . '_data'] = Helper::hash_data( $this->info['filenamepath'],
                                                                       $this->info['avdataoffset'],
                                                                       $this->info['avdataend'],
                                                                       $algorithm );
            }
            else
            {

                // get hash from whole file
                switch ( $algorithm )
                {
                    case 'md5':
                        $this->info[$algorithm . '_data'] = md5_file( $this->info['filenamepath'] );
                        break;

                    case 'sha1':
                        $this->info[$algorithm . '_data'] = sha1_file( $this->info['filenamepath'] );
                        break;
                }
            }
        }

        return true;
    }

    /**
     *
     */
    public function ChannelsBitratePlaytimeCalculations()
    {

        // set channelmode on audio
        if ( !empty( $this->info['audio']['channelmode'] ) || !isset( $this->info['audio']['channels'] ) )
        {
            // ignore
        }
        elseif ( $this->info['audio']['channels'] == 1 )
        {
            $this->info['audio']['channelmode'] = 'mono';
        }
        elseif ( $this->info['audio']['channels'] == 2 )
        {
            $this->info['audio']['channelmode'] = 'stereo';
        }

        // Calculate combined bitrate - audio + video
        $CombinedBitrate = 0;
        $CombinedBitrate += ( isset( $this->info['audio']['bitrate'] ) ? $this->info['audio']['bitrate'] : 0 );
        $CombinedBitrate += ( isset( $this->info['video']['bitrate'] ) ? $this->info['video']['bitrate'] : 0 );
        if ( ( $CombinedBitrate > 0 ) && empty( $this->info['bitrate'] ) )
        {
            $this->info['bitrate'] = $CombinedBitrate;
        }
        //if ((isset($this->info['video']) && !isset($this->info['video']['bitrate'])) || (isset($this->info['audio']) && !isset($this->info['audio']['bitrate']))) {
        //	// for example, VBR MPEG video files cannot determine video bitrate:
        //	// should not set overall bitrate and playtime from audio bitrate only
        //	unset($this->info['bitrate']);
        //}
        // video bitrate undetermined, but calculable
        if ( isset( $this->info['video']['dataformat'] ) && $this->info['video']['dataformat'] && ( !isset( $this->info['video']['bitrate'] ) || ( $this->info['video']['bitrate'] == 0 ) ) )
        {
            // if video bitrate not set
            if ( isset( $this->info['audio']['bitrate'] ) && ( $this->info['audio']['bitrate'] > 0 ) && ( $this->info['audio']['bitrate'] == $this->info['bitrate'] ) )
            {
                // AND if audio bitrate is set to same as overall bitrate
                if ( isset( $this->info['playtime_seconds'] ) && ( $this->info['playtime_seconds'] > 0 ) )
                {
                    // AND if playtime is set
                    if ( isset( $this->info['avdataend'] ) && isset( $this->info['avdataoffset'] ) )
                    {
                        // AND if AV data offset start/end is known
                        // THEN we can calculate the video bitrate
                        $this->info['bitrate']          = round( ( ( $this->info['avdataend'] - $this->info['avdataoffset'] ) * 8 ) / $this->info['playtime_seconds'] );
                        $this->info['video']['bitrate'] = $this->info['bitrate'] - $this->info['audio']['bitrate'];
                    }
                }
            }
        }

        if ( ( !isset( $this->info['playtime_seconds'] ) || ( $this->info['playtime_seconds'] <= 0 ) ) && !empty( $this->info['bitrate'] ) )
        {
            $this->info['playtime_seconds'] = ( ( $this->info['avdataend'] - $this->info['avdataoffset'] ) * 8 ) / $this->info['bitrate'];
        }

        if ( !isset( $this->info['bitrate'] ) && !empty( $this->info['playtime_seconds'] ) )
        {
            $this->info['bitrate'] = ( ( $this->info['avdataend'] - $this->info['avdataoffset'] ) * 8 ) / $this->info['playtime_seconds'];
        }
        if ( isset( $this->info['bitrate'] ) && empty( $this->info['audio']['bitrate'] ) && empty( $this->info['video']['bitrate'] ) )
        {
            if ( isset( $this->info['audio']['dataformat'] ) && empty( $this->info['video']['resolution_x'] ) )
            {
                // audio only
                $this->info['audio']['bitrate'] = $this->info['bitrate'];
            }
            elseif ( isset( $this->info['video']['resolution_x'] ) && empty( $this->info['audio']['dataformat'] ) )
            {
                // video only
                $this->info['video']['bitrate'] = $this->info['bitrate'];
            }
        }

        // Set playtime string
        if ( !empty( $this->info['playtime_seconds'] ) && empty( $this->info['playtime_string'] ) )
        {
            $this->info['playtime_string'] = Helper::PlaytimeString( $this->info['playtime_seconds'] );
        }
    }

    /**
     * @return bool
     */
    public function CalculateCompressionRatioVideo()
    {
        if ( empty( $this->info['video'] ) )
        {
            return false;
        }
        if ( empty( $this->info['video']['resolution_x'] ) || empty( $this->info['video']['resolution_y'] ) )
        {
            return false;
        }
        if ( empty( $this->info['video']['bits_per_sample'] ) )
        {
            return false;
        }

        switch ( $this->info['video']['dataformat'] )
        {
            case 'bmp':
            case 'gif':
            case 'jpeg':
            case 'jpg':
            case 'png':
            case 'tiff':
                $FrameRate         = 1;
                $PlaytimeSeconds   = 1;
                $BitrateCompressed = $this->info['filesize'] * 8;
                break;

            default:
                if ( !empty( $this->info['video']['frame_rate'] ) )
                {
                    $FrameRate = $this->info['video']['frame_rate'];
                }
                else
                {
                    return false;
                }
                if ( !empty( $this->info['playtime_seconds'] ) )
                {
                    $PlaytimeSeconds = $this->info['playtime_seconds'];
                }
                else
                {
                    return false;
                }
                if ( !empty( $this->info['video']['bitrate'] ) )
                {
                    $BitrateCompressed = $this->info['video']['bitrate'];
                }
                else
                {
                    return false;
                }
                break;
        }
        $BitrateUncompressed = $this->info['video']['resolution_x'] * $this->info['video']['resolution_y'] * $this->info['video']['bits_per_sample'] * $FrameRate;

        $this->info['video']['compression_ratio'] = $BitrateCompressed / $BitrateUncompressed;

        return true;
    }

    /**
     * @return bool
     */
    public function CalculateCompressionRatioAudio()
    {
        if ( empty( $this->info['audio']['bitrate'] ) || empty( $this->info['audio']['channels'] ) || empty( $this->info['audio']['sample_rate'] ) )
        {
            return false;
        }
        $this->info['audio']['compression_ratio'] = $this->info['audio']['bitrate'] / ( $this->info['audio']['channels'] * $this->info['audio']['sample_rate'] * ( !empty( $this->info['audio']['bits_per_sample'] ) ? $this->info['audio']['bits_per_sample'] : 16 ) );

        if ( !empty( $this->info['audio']['streams'] ) )
        {
            foreach ( $this->info['audio']['streams'] as $streamnumber => $streamdata )
            {
                if ( !empty( $streamdata['bitrate'] ) && !empty( $streamdata['channels'] ) && !empty( $streamdata['sample_rate'] ) )
                {
                    $this->info['audio']['streams'][$streamnumber]['compression_ratio'] = $streamdata['bitrate'] / ( $streamdata['channels'] * $streamdata['sample_rate'] * ( !empty( $streamdata['bits_per_sample'] ) ? $streamdata['bits_per_sample'] : 16 ) );
                }
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function CalculateReplayGain()
    {
        if ( isset( $this->info['replay_gain'] ) )
        {
            if ( !isset( $this->info['replay_gain']['reference_volume'] ) )
            {
                $this->info['replay_gain']['reference_volume'] = (double) 89.0;
            }
            if ( isset( $this->info['replay_gain']['track']['adjustment'] ) )
            {
                $this->info['replay_gain']['track']['volume'] = $this->info['replay_gain']['reference_volume'] - $this->info['replay_gain']['track']['adjustment'];
            }
            if ( isset( $this->info['replay_gain']['album']['adjustment'] ) )
            {
                $this->info['replay_gain']['album']['volume'] = $this->info['replay_gain']['reference_volume'] - $this->info['replay_gain']['album']['adjustment'];
            }

            if ( isset( $this->info['replay_gain']['track']['peak'] ) )
            {
                $this->info['replay_gain']['track']['max_noclip_gain'] = 0 - Helper::RGADamplitude2dB( $this->info['replay_gain']['track']['peak'] );
            }
            if ( isset( $this->info['replay_gain']['album']['peak'] ) )
            {
                $this->info['replay_gain']['album']['max_noclip_gain'] = 0 - Helper::RGADamplitude2dB( $this->info['replay_gain']['album']['peak'] );
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function ProcessAudioStreams()
    {
        if ( !empty( $this->info['audio']['bitrate'] ) || !empty( $this->info['audio']['channels'] ) || !empty( $this->info['audio']['sample_rate'] ) )
        {
            if ( !isset( $this->info['audio']['streams'] ) )
            {
                foreach ( $this->info['audio'] as $key => $value )
                {
                    if ( $key != 'streams' )
                    {
                        $this->info['audio']['streams'][0][$key] = $value;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @return string
     */
    public function GetId3_tempnam()
    {
        return tempnam( $this->tempdir, 'gI3' );
    }

    /**
     *
     */
    public static function getTempDir()
    {
        if ( null === self::$TempDir )
        {
            $temp_dir = ini_get( 'upload_tmp_dir' );
            if ( $temp_dir && ( !is_dir( $temp_dir ) || !is_readable( $temp_dir ) ) )
            {
                $temp_dir = '';
            }
            if ( !$temp_dir && function_exists( 'sys_get_temp_dir' ) )
            {
                // PHP v5.2.1+
                // sys_get_temp_dir() may give inaccessible temp dir, e.g. with open_basedir on virtual hosts
                $temp_dir = sys_get_temp_dir();
            }
            $temp_dir     = realpath( $temp_dir );
            $open_basedir = ini_get( 'open_basedir' );

            if ( $open_basedir )
            {
                // e.g. "/var/www/vhosts/getid3.org/httpdocs/:/tmp/"
                $temp_dir     = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR,
                                             $temp_dir );
                $open_basedir = str_replace( [ '/', '\\' ],
                                             DIRECTORY_SEPARATOR, $open_basedir );
                if ( substr( $temp_dir, -1, 1 ) != DIRECTORY_SEPARATOR )
                {
                    $temp_dir .= DIRECTORY_SEPARATOR;
                }
                $found_valid_tempdir = false;
                $open_basedirs       = explode( ':', $open_basedir );
                foreach ( $open_basedirs as $basedir )
                {
                    if ( substr( $basedir, -1, 1 ) != DIRECTORY_SEPARATOR )
                    {
                        $basedir .= DIRECTORY_SEPARATOR;
                    }
                    if ( preg_match( '#^' . preg_quote( $basedir ) . '#', $temp_dir ) )
                    {
                        $found_valid_tempdir = true;
                        break;
                    }
                }
                if ( !$found_valid_tempdir )
                {
                    $temp_dir = '';
                }
                unset( $open_basedirs, $found_valid_tempdir, $basedir );
            }
            if ( !$temp_dir )
            {
                $temp_dir = '*'; // invalid directory name should force tempnam() to use system default temp dir
            }
            self::$TempDir = $temp_dir;
            unset( $open_basedir, $temp_dir );
        }

        return self::$TempDir;
    }

    /**
     * @return bool|string
     */
    public static function environmentIsWindows()
    {
        // define a static property rather than looking up every time it is needed
        if ( null === self::$EnvironmentIsWindows )
        {
            self::$EnvironmentIsWindows = strtolower( substr( PHP_OS, 0, 3 ) ) == 'win';
        }

        return self::$EnvironmentIsWindows;
    }

    /**
     *
     */
    public static function getIncludePath()
    {
        // Get base path of GetId3Core() - ONCE
        if ( null === self::$IncludePath )
        {
            foreach ( get_included_files() as $val )
            {
                if ( basename( $val ) == 'getid3.php' )
                {
                    self::$IncludePath = dirname( $val ) . DIRECTORY_SEPARATOR;
                    break;
                }
            }
        }

        return self::$IncludePath;
    }

    public function getEncoding()
    {
        return $this->encoding;
    }

    public function setEncoding( $encoding )
    {
        $this->encoding = $encoding;

        return $this;
    }

    public function getEncodingId3v1()
    {
        return $this->encoding_id3v1;
    }

    public function setEncodingId3v1( $encoding_id3v1 )
    {
        $this->encoding_id3v1 = $encoding_id3v1;

        return $this;
    }

    public function getOptionTagId3v1()
    {
        return $this->option_tag_id3v1;
    }

    public function setOptionTagId3v1( $option_tag_id3v1 )
    {
        $this->option_tag_id3v1 = $option_tag_id3v1;

        return $this;
    }

    public function getOptionTagId3v2()
    {
        return $this->option_tag_id3v2;
    }

    public function setOptionTagId3v2( $option_tag_id3v2 )
    {
        $this->option_tag_id3v2 = $option_tag_id3v2;

        return $this;
    }

    public function getOptionTagLyrics3()
    {
        return $this->option_tag_lyrics3;
    }

    public function setOptionTagLyrics3( $option_tag_lyrics3 )
    {
        $this->option_tag_lyrics3 = $option_tag_lyrics3;

        return $this;
    }

    public function getOptionTagApetag()
    {
        return $this->option_tag_apetag;
    }

    public function setOptionTagApetag( $option_tag_apetag )
    {
        $this->option_tag_apetag = $option_tag_apetag;

        return $this;
    }

    public function getOptionTagsProcess()
    {
        return $this->option_tags_process;
    }

    public function setOptionTagsProcess( $option_tags_process )
    {
        $this->option_tags_process = $option_tags_process;

        return $this;
    }

    public function getOptionTagsHtml()
    {
        return $this->option_tags_html;
    }

    public function setOptionTagsHtml( $option_tags_html )
    {
        $this->option_tags_html = $option_tags_html;

        return $this;
    }

    public function getOptionExtraInfo()
    {
        return $this->option_extra_info;
    }

    public function setOptionExtraInfo( $option_extra_info )
    {
        $this->option_extra_info = $option_extra_info;

        return $this;
    }

    public function getOptionSaveAttachments()
    {
        return $this->option_save_attachments;
    }

    public function setOptionSaveAttachments( $option_save_attachments )
    {
        $this->option_save_attachments = $option_save_attachments;

        return $this;
    }

    public function getOptionMD5Data()
    {
        return $this->option_md5_data;
    }

    public function setOptionMD5Data( $option_md5_data )
    {
        $this->option_md5_data = $option_md5_data;

        return $this;
    }

    public function getOptionMD5DataDource()
    {
        return $this->option_md5_data_source;
    }

    public function setOptionMD5DataSource( $option_md5_data_source )
    {
        $this->option_md5_data_source = $option_md5_data_source;

        return $this;
    }

    public function getOptionSha1Data()
    {
        return $this->option_sha1_data;
    }

    public function setOptionSha1Data( $option_sha1_data )
    {
        $this->option_sha1_data = $option_sha1_data;

        return $this;
    }

    public function getOptionMax2gbCheck()
    {
        return $this->option_max_2gb_check;
    }

    public function setOptionMax2gbCheck( $option_max_2gb_check )
    {
        $this->option_max_2gb_check = $option_max_2gb_check;

        return $this;
    }

    public function getOptionFreadBufferSize()
    {
        return $this->option_fread_buffer_size;
    }

    public function setOptionFreadBufferSize( $option_fread_buffer_size )
    {
        $this->option_fread_buffer_size = $option_fread_buffer_size;

        return $this;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function setFilename( $filename )
    {
        $this->filename = $filename;

        return $this;
    }

    public function getFp()
    {
        return $this->fp;
    }

    public function setFp( $fp )
    {
        $this->fp = $fp;

        return $this;
    }

    public function getInfo()
    {
        return $this->info;
    }

    public function setInfo( array $info = [] )
    {
        $this->info = $info;

        return $this;
    }

    public function addInfo( $key, $info )
    {
        $this->info[$key] = $info;

        return $this;
    }

    public function getStartupError()
    {
        return $this->startup_error;
    }

    public function hasStartupError()
    {
        return !empty( $this->startup_error );
    }

    public function setStartupError( $startup_error )
    {
        $this->startup_error = $startup_error;

        return $this;
    }

    public function addStartupError( $startup_error )
    {
        $this->startup_error .= $startup_error . PHP_EOL;

        return $this;
    }

    public function getStartupWarning()
    {
        return $this->startup_warning;
    }

    public function hasStartupWarning()
    {
        return !empty( $this->startup_warning );
    }

    public function setStartupWarning( $startup_warning )
    {
        $this->startup_warning = $startup_warning;

        return $this;
    }

    public function addStartupWarning( $startup_warning )
    {
        $this->startup_warning .= $startup_warning . PHP_EOL;

        return $this;
    }

    public function getMemoryLimit()
    {
        return $this->memory_limit;
    }

    public function setMemoryLimit( $memory_limit )
    {
        $this->memory_limit = $memory_limit;

        return $this;
    }
}
