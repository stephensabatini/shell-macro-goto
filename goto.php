<?php
/**
 * Shell Macro: goto
 *
 * Creates a macro to use for quickly navigating between WordPress projects and project parts quickly and efficiently.
 * This macro relies on that you use a Projects folder in your user folder, and that your database names are named
 * according to example_com, or matching your project directory where periods (.) are transformed to underscores
 * (_).
 *
 * @example goto <project> <location> | <project> | <location>
 * @example goto example.com
 * @example goto [root|themes|plugins|parent|child]
 * @example goto example.com [root|themes|plugins|parent|child]
 *
 * @version 1.0.0
 * @author Stephen Sabatini <info@stephensabatini.com>
 * @copyright 2019 Stephen Sabatini
 * @license https://opensource.org/licenses/gpl-license.php GNU Public License
 */

class GoTo_Macro {

    /**
     * @var string $project This is the hostname for the project (without the www.)
     * @var string $location
     * @var bool $has_project_specified
     * @var bool $has_location_specified
     * @var string $new_directory The directory that you will be navigating to that in ran the the `cd` in Shell.
     */
    protected $project = null;
    protected $location = null;
    protected $has_project_specified = false;
    protected $has_location_specified = false;
    protected $new_directory = '';

    public function __construct() {
        $this->setup();
        $this->build();
        $this->execute();
    }

    protected function setup() {

        /** Only let this script run over the command line. */
        if ('cli' !== php_sapi_name()) {
            $this->log_error('This script is only meant to run via the CLI.');
        }

        /**
         * Setup the variables.
         *
         * We only ever expect 2-3 variables. The first is the CWD, the second and third are the project domain and
         * where to navigate to within that project (themes, plugins, parent or child), in no specific order, where one
         * may be provided but the other might not be. It's easy to detect the domain because of the TLD.
         */
        if (is_array($GLOBALS) && array_key_exists('argv', $GLOBALS)) {

            /**
             * If there is more than two parameters provided (We count 3 because the first item is the CWD), throw an
             * error. Otherwise, continue.
             */
            if (4 > count($GLOBALS['argv'])) {
                foreach ($GLOBALS['argv'] as $index => $arg) {
                    /** Skip first item because it's the CWD. */
                    if (0 === $index) continue;

                    /** Detect if this is a valid domain. */
                    if (false === $this->has_project_specified
                        && (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $arg) // Valid chars check
                        && preg_match("/^.{1,253}$/", $arg) // Overall length check
                        && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $arg) // Length of each label
                        && false !== strpos($arg, '.')) // Don't allow domains without TLDs.
                    ) {
                        $this->has_project_specified = true;
                        $this->project = $arg;
                    } else {
                        $this->has_location_specified = true;
                        $this->location = $arg;
                    }
                }
            } else {
                $this->log_error('This command only accepts up to two parameters.');
            }

        } else {
            /** If the required variables for the macro are not provided, throw an error. */
            $this->log_error('The global variable argv is not set.');
        }

        /**
         * If just the location has been provided but not the project (e.g. `goto parent`), see what subdirectory of
         * Projects we're under to detect what project we're in automatically and allow us to navigate it.
         */
        if (!$this->has_project_specified && $this->has_location_specified) {
            // Try to detect the project directory since it's not specified.
            $string = ' ' . getcwd();
            $start = dirname($GLOBALS['argv'][0]).'/';
            $ini = strpos($string, $start);
            if ($ini == 0) return '';
            $ini += strlen($start);
            $len = strpos("$string/", '/', $ini) - $ini;
            $this->project = substr($string, $ini, $len);
            if (false !== $this->project) {
                $this->has_project_specified = true;
            } else {
                /**
                 * If the you're try ing to navigate to a location in a project without specifying a project, and
                 * outside of a project, throw an error.
                 */
                $this->log_error('You need to be in a project to use the shorthand `goto` macro. Please specify a project.');
            }
        }

    }

    protected function build() {

        /**
         * If both the project and location have been provided (e.g. `goto example.com child`) or the project was
         * automatically detected and assigned up above, continue.
         */
        if ($this->has_project_specified && $this->has_location_specified) {
            /**
             * Connect to the database
             *
             * If we're trying to navigate to the parent or the child theme, we need to connect to the database
             * to detect what the active parent or child theme is in the `wp_options` table.
             *
             * @var string $content_directory
             * @var string $theme_directory
             * @var string $plugins_directory
             * @var string $db_name All of our local databases adopt the format domain_tld where all periods become underscores in the hostname.
             * @var string $option_name If requesting parent this is "template". If requesting child this is "stylesheet". This is used to fetch our current parent/child theme from the database.
             * @var object $pdo
             */
            $content_directory = $this->project.'/wp-content';
            $theme_directory =  "$content_directory/themes";
            $plugins_directory =  "$content_directory/plugins";

            if ('child' === $this->location || 'parent' === $this->location) {

                $db_name = parse_url('https://'.str_replace('.', '_', $this->project), PHP_URL_HOST);
                $option_name = '';

                try {
                    $pdo = new PDO("mysql:host=127.0.0.1;dbname=$db_name", 'root', 'root');
                } catch(PDOException $err) {
                    $this->log_error($err->getMessage());
                }

                /**
                 * If we're trying to navigate to the parent, query the database following this conditional to detect
                 * the parent theme and set it as the new directory we are navigating to.
                 */
                if ('parent' === $this->location) {
                    $option_name = 'template';
                }

                /**
                 * If we're trying to navigate to the child, query the database following this contitional to detect
                 * the child theme and set it as the new directory we are navigating to.
                 */
                elseif ('child' === $this->location) {
                    $option_name = 'stylesheet';
                }

                $theme = $pdo->query("SELECT option_value FROM wp_options WHERE option_name = '$option_name'")->fetch(PDO::FETCH_ASSOC)['option_value'];
                $this->new_directory = "$theme_directory/$theme";

            } elseif ('plugins' === $this->location) {
                $this->new_directory = $plugins_directory;
            } elseif ('themes' === $this->location) {
                $this->new_directory = $theme_directory;
            } elseif ('root' === $this->location) {
                $this->new_directory = $this->project;
            } else {
                $this->new_directory = $this->project;
            }
        }

        /** If just the project has been provided but not the location. (e.g. `goto example.com`) */
        elseif ($this->has_project_specified) {
            $this->new_directory = $this->project;
        }

        /** If no parameters have been provided. (e.g. `goto`) */
        else {
            $this->log_error('Error. Insufficient parameters.');
        }

    }

    protected function execute() {
        echo $this->new_directory;
    }

    /**
     * Logs error to console and kills execution of this script.
     *
     * @param string $error_message
     */
    protected function log_error(string $error_message) {
        exit($error_message);
    }
}
new GoTo_Macro;
