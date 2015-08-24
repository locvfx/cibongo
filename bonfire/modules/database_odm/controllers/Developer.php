<?php defined('BASEPATH') || exit('No direct script access allowed');
/**
 * CIBongo
 *
 * An open source project to allow developers to jumpstart their development of
 * CodeIgniter applications
 *
 * @package   Bonfire
 */

/**
 * Various tools to manage the Mongo Collections.
 *
 * Requirements:
 * Following items must be installed:
 * 
 * pecl install zip
 * mongodump
 * mongorestore
 * 
 * @package Bonfire\Modules\Database_odm\Controllers\Developer
 */
class Developer extends Admin_Controller
{
    /** @var string Path to the backups (relative to APPPATH) */
    private $backup_folder  = 'db/backups/';

    //--------------------------------------------------------------------------

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->auth->restrict('Bonfire.Database.Manage');
        $this->lang->load('database');

        $this->backup_folder = APPPATH . $this->backup_folder;

        $this->dbConfig = $this->doctrineodm->dm->getConfiguration();
        $this->dbName = $this->dbConfig->getDefaultDB();
        $this->mongo = $this->doctrineodm->dm
                ->getConnection()
                ->getMongo()
                ->selectDB($this->dbName);
        
        Assets::add_module_css('database_odm', 'database');

        Template::set_block('sub_nav', 'developer/_sub_nav');
    }

    /**
     * Display a list of tables in the database.
     *
     * @return void
     */
    public function index()
    {
        // Set the default toolbar_title for this page. The actions may change it.
        Template::set('toolbar_title', lang('database_title_maintenance'));
        
        // Performing an action (backup/repair/optimize/drop)? If the action sets
        // a different view and/or sets the $tables variable itself, it should return
        // true to indicate that this method should not prep the form.
        $hideForm = false;
        if (isset($_POST['action'])) {
            $checked = $this->input->post('checked');

            switch ($this->input->post('action')) {
                case 'backup':
                    $hideForm = $this->backup($checked);
                    break;
                case 'repair':
                    $this->repair($checked);
                    break;
                case 'optimize':
                    $this->optimize();
                    break;
                case 'drop':
                    $hideForm = $this->drop($checked);
                    break;
                default:
                    Template::set_message(lang('database_action_unknown'), 'warning');
            }
        }

        if (! $hideForm) {
            // Load number helper for byte_format() function.
            $this->load->helper('number');

            Template::set('tables', $this->showTableStatus());
        }

        Template::render();
    }

    /**
     * Browse the DB tables.
     *
     * Displays the table data when the user selects one of the linked table names
     * from the index.
     *
     * @param string $table Name of the table to browse
     *
     * @return void
     */
    public function browse($table = '')
    {
        if (empty($table)) {
            Template::set_message(lang('database_no_table_name'), 'error');
            redirect(SITE_AREA . '/developer/database');
        }

        //$query = $this->db->get($table);
        $query = $this->mongo->{$table}->find();
        if ($query->count()) {
            $rows = array();
            foreach($query as $row) {
                $rows[] = $row;
            }
            Template::set('rows', $rows);
        }

        Template::set('num_rows', $query->count());
        Template::set('query', 'db.' . $table . '.find()');
        Template::set('toolbar_title', sprintf(lang('database_browse'), $table));
        Template::render();
    }

    /**
     * List the existing backups.
     *
     * @return void
     */
    public function backups()
    {
        if (isset($_POST['delete'])) {
            // Make sure something is selected to delete.
            $checked = $this->input->post('checked');
            if (empty($checked) || ! is_array($checked)) {
                // No files selected.
                Template::set_message(lang('database_backup_delete_none'), 'error');
            } else {
                // Delete the files.
                $failed = 0;
                foreach ($checked as $file) {
                    $deleted = unlink($this->backup_folder . $file);
                    if (! $deleted) {
                        ++$failed;
                    }
                }

                $deletedCount = sprintf(lang('database_backup_deleted_count'), count($checked) - $failed);
                if ($failed == 0) {
                    Template::set_message($deletedCount, 'success');
                } else {
                    Template::set_message(
                        "{$deletedCount}<br />" . lang('database_backup_deleted_error'),
                        'error'
                    );
                }
            }
        }

        // Load the number helper for the byte_format() function used in the view.
        $this->load->helper('number');

        // Get a list of existing backup files
        $this->load->helper('file');

        Template::set('backups', get_dir_file_info($this->backup_folder));
        Template::set('toolbar_title', lang('database_title_backups'));
        Template::render();
    }

    /**
     * Performs the actual backup.
     *
     * @param array $tables Array of tables
     *
     * @return bool
     */
    public function backup($tables = null)
    {
        if (! empty($tables) && is_array($tables)) {
            // set_view() is used because execution most likely came here from index()
            // rather than via redirect or post.
            Template::set_view('developer/backup');

            // Set the selected tables in the form.
            Template::set('tables', $tables);
            Template::set('file', ENVIRONMENT . '_backup_' . date('Y-m-j_His'));
            Template::set('toolbar_title', lang('database_title_backup_create'));

            // Return to index() and display the backup form.
            return true;
        }

        if (isset($_POST['backup'])) {
            // The backup form has been posted, perform validation.
            $this->load->library('form_validation');

            $this->form_validation->set_rules('file_name', 'lang:database_filename', 'required|trim|max_length[220]');
            //$this->form_validation->set_rules('drop_tables', 'lang:database_drop_tables', 'required|trim|one_of[0,1]');
            //$this->form_validation->set_rules('add_inserts', 'lang:database_add_inserts', 'required|trim|one_of[0,1]');
            $this->form_validation->set_rules('file_type', 'lang:database_compress_type', 'required|trim|one_of[txt,gzip,zip]');
            $this->form_validation->set_rules('tables[]', 'lang:database_tables', 'required');

            if ($this->form_validation->run() !== false) {
                // Perform the backup.
                //$this->load->dbutil();

                $format   = $_POST['file_type'];
                $filename = $this->backup_folder . $_POST['file_name'];

                $prefs = array(
                    'format'     => $format,
                    'filename'   => $filename,
                    'basename'   => $basename,
                    'tables'     => $_POST['tables'],
                );
                
                $backup_file = $this->_backup($prefs);

                if (file_exists($backup_file)) {
                    $backup_file_basename = basename($backup_file);
                    Template::set_message(
                        sprintf(
                            lang('database_backup_success'),
                            html_escape(site_url(SITE_AREA . "/developer/database_odm/get_backup/{$backup_file_basename}")),
                            html_escape($backup_file_basename)
                        ),
                        'success'
                    );
                } else {
                    Template::set_message(lang('database_backup_failure'), 'error');
                }

                redirect(SITE_AREA . '/developer/database_odm');
            }

            // Validation failed.
            Template::set('tables', $this->input->post('tables'));
            Template::set_message(lang('database_backup_failure_validation'), 'error');

        }

        Template::set('toolbar_title', lang('database_title_backup_create'));
        Template::render();
    }

    /**
     * Do a force download on a backup file.
     *
     * @param string $filename Name of the file to download.
     *
     * @return void
     */
    public function get_backup($filename = null)
    {
        // CSRF could try `../../dev/temperamental-special-file` or `COM1` and the
        // possibility of that happening should really be prevented.
        if (preg_match('{[\\/]}', $filename) || ! fnmatch('*.*', $filename)) {
            $this->security->csrf_show_error();
        }

        $backupFile = "{$this->backup_folder}{$filename}";
        if (! file_exists($backupFile)) {
            Template::set_message(sprintf(lang('database_get_backup_error'), $filename), 'error');
            redirect(SITE_AREA . '/developer/database_odm/backups');
        }

        $data = file_get_contents($backupFile);

        $this->load->helper('download');
        force_download($filename, $data);

        redirect(SITE_AREA . '/developer/database_odm/backups');
    }

    /**
     * Perform a restore from a database backup.
     *
     * @param string $filename Name of the file to restore.
     *
     * @return void
     */
    public function restore($filename = null)
    {
        Template::set('filename', $filename);

        if (isset($_POST['restore']) && ! empty($filename)) {
            $backupFile = "{$this->backup_folder}{$filename}";

            // Load the file from disk.
            $file = file($backupFile);
            if (empty($file)) {
                // Couldn't read from file.
                Template::set_message(sprintf(lang('database_restore_read_error'), $backupFile), 'error');
                redirect(SITE_AREA . '/developer/database_odm/backups');
            }

            $extract_dir = basename($file);
            $zip = new ZipArchive();
            $zip->open($file);
            $zip->extractTo($extract_dir);
            $zip->close();  
            
            // Loop through each line, building the query until it is complete,
            // then executing the query and recording the results.
            $queryResults = array();
            $currentQuery = '';
            foreach ($file as $line) {
                // Skip it if it's a comment.
                if (substr(trim($line), 0, 1) == '#') {
                    continue;
                }

                // Add this line to the current query.
                $currentQuery .= $line;

                // If there is no semicolon at the end, move on to the next line.
                if (substr(trim($line), -1, 1) != ';') {
                    continue;
                }

                // Found a semicolon, perform the query and store the results.
                if ($this->db->query($currentQuery)) {
                    $queryResults[] = sprintf(lang('database_restore_out_successful'), $currentQuery);
                } else {
                    $queryResults[] = sprintf(lang('database_restore_out_unsuccessful'), $currentQuery);
                }

                // Reset $currentQuery and move on to the next line.
                $currentQuery = '';
            }

            // Output the results.
            Template::set('results', implode('<br />', $queryResults));
        }

        // Show verification screen.
        Template::set_view('developer/restore');
        Template::set('toolbar_title', lang('database_title_restore'));
        Template::render();
    }

    /**
     * Drop database tables.
     *
     * @param array $tables Array of table to drop
     *
     * @return bool
     */
    public function drop($tables = null)
    {
        if (! empty($tables)) {
            // set_view() is used because execution most likely came here from index()
            // rather than via redirect or post.
            Template::set_view('developer/drop');
            Template::set('tables', $tables);

            // Return to index() and display the verification screen.
            return true;
        }

        if (empty($_POST['tables']) || ! is_array($_POST['tables'])) {
            // No tables were selected.
            Template::set_message(lang('database_drop_none'), 'error');
            redirect(SITE_AREA . '/developer/database_odm');
        }

        // Delete the tables....
        $this->load->dbforge();

        $notDropped = 0;
        foreach ($_POST['tables'] as $table) {
            // dbforge automatically adds the prefix, so remove it, if present.
            // This may cause problems if there is a table with the prefix duplicated,
            // e.g. bf_bf_table.
            $prefix = $this->db->dbprefix;
            if (strncmp($table, $prefix, strlen($prefix)) === 0) {
                $table = substr($table, strlen($prefix));
            }

            if (@$this->dbforge->drop_table($table) === false) {
                ++$notDropped;
            }
        }

        $tableCount = count($_POST['tables']) - $notDropped;
        Template::set_message(
            sprintf(
                $tableCount == 1 ? lang('database_drop_success_singular') : lang('database_drop_success_plural'),
                $tableCount
            ),
            $notDropped == 0 ? 'success' : 'error'
        );

        redirect(SITE_AREA . '/developer/database_odm');
    }

    //--------------------------------------------------------------------------
    // Private methods.
    //--------------------------------------------------------------------------

    /**
     * Repair database tables.
     *
     * @param array $tables The names of tables to repair.
     *
     * @return void
     */
    private function repair($tables = null)
    {
        if (empty($tables) || ! is_array($tables)) {
            // No tables selected
            Template::set_message(lang('database_repair_none'), 'error');
            redirect(SITE_AREA . '/developer/database_odm');
        }

        $this->load->dbutil();

        // Repair the tables, tracking the number of failures.
        $failed = 0;
        foreach ($tables as $table) {
            if (! $this->dbutil->repair_table($table)) {
                ++$failed;
            }
        }

        Template::set_message(
            sprintf(lang('database_repair_success'), count($tables) - $failed, count($tables)),
            $failed == 0 ? 'success' : 'info'
        );

        redirect(SITE_AREA . '/developer/database_odm');
    }

    /**
     * Optimize the entire database.
     *
     * @return void
     */
    private function optimize()
    {
        $this->load->dbutil();

        if ($result = $this->dbutil->optimize_database()) {
            Template::set_message(lang('database_optimize_success'), 'success');
        } else {
            Template::set_message(lang('database_optimize_failure'), 'error');
        }

        redirect(SITE_AREA . '/developer/database_odm');
    }

    /**
     * Get the data returned by MySQL's 'SHOW TABLE STATUS'.
     *
     * @todo Implement the this functionality for platforms other than MySQL.
     *
     * @return object[] An array of objects containing information about the tables
     * in the database.
     */
    private function showTableStatus()
    {
        // Since the table is built from a database-specific query, check the platform
        // to allow for other methods of generating the table.
        $platform = strtolower($this->db->platform());

        // ---------------------------------------------------------------------
        // MySQL.
        // ---------------------------------------------------------------------

        $collections = $this->mongo->listCollections();
        
        /*
        if (in_array($platform, array('mysql', 'mysqli', 'bfmysqli'))) {
            return $this->db->query('SHOW TABLE STATUS')->result();
        }
        */
        
        // ---------------------------------------------------------------------
        // All other databases.
        // ---------------------------------------------------------------------
        $platform = 'MongoDB';
        
        $tables = array();
        $table  = new stdClass();

        // In the absence of information from the database, display the platform.
        $table->Engine = $platform;

        // These fields are currently unsupported.
        $table->Data_length  = lang('database_data_size_unsupported');   // The length of the data file.
        $table->Index_length = lang('database_index_field_unsupported'); // The length of the index file.
        $table->Data_free    = lang('database_data_free_unsupported');   // The number of allocated but unused bytes.

        // Set the metadata for each table.
        foreach ($collections as $collection) {
            $table->Name = $collection->getName();
            
            $table->Rows = $collection->count();

            // @see http://docs.mongodb.org/manual/reference/command/collStats/
            $stats = $this->mongo->execute("db." . $table->Name . ".stats()");
            //Kint::dump($stats);
            $table->Data_length = $stats['retval']['size'];
            $table->Index_length = $stats['retval']['totalIndexSize'];
            
            // Use clone() to copy the current state of $table into $tables (otherwise,
            // an array of references to the metadata for the last table is created,
            // which isn't very useful).
            $tables[] = clone($table);
        }

        return $tables;
    }
    
    private function _backup($prefs) 
    {
        //use mongodump command line
        //mongodump --host mongodb1.example.net --port 37017 --username user --password pass --out /opt/backup/mongodump-2011-10-24
        //mongodump  --db test --collection collection
        //$this->load->config('user_meta');
        
        //make sure mongodump installed:
        $mongodump = trim(`which mongodump`);
        

        //print "<Pre>"; Kint::dump($db); exit;
        
        if(empty($mongodump)) {
            //error... mongodump not installed.
            return false;
        }
        
        if(!is_dir($this->backup_folder)) {
            //bad backup directory
            return false;
        }
        
        //@TODO should be a better/more flexible way to pull creds
        require APPPATH . 'config/doctrine.php';

        $username = "";
        if(!empty($db['default']['username'])) {
            $username = "--username " . $db['default']['username'];
        }

        $password = "";
        if(!empty($db['default']['password'])) {
            $password = "--password " . $db['default']['password'];
        }

        $port = "";
        if(!empty($db['default']['port'])) {
            $port = "--port " . $db['default']['port'];
        }

        $hostname = "";
        if(!empty($db['default']['hostname'])) {
            $hostname = "--host " . $db['default']['hostname'];
        }

        $database = "";
        if(!empty($db['default']['database'])) {
            $database = "--db " . $db['default']['database'];
        }


        if(empty($prefs['tables'])) {
            //dump entire db:
            $cmd = "$mongodump $hostname $port $username $password $database --out {$prefs['filename']}";
            exec($cmd, $output, $return);
        }
        else {
            //dump selected collections
            foreach($prefs['tables'] as $collection) {
                $cmd = "$mongodump $hostname $port $username $password $database --collection $collection --out {$prefs['filename']}";
                exec($cmd, $output, $return);
            }
        }
        
        // Was a Zip file requested?
        if ($prefs['format'] === 'zip')
        {
            // Load the Zip class and output it
            $this->load->library('zip');
            $this->zip->add_dir($prefs['filename']);
            $this->zip->archive($prefs['filename'] . ".zip");
           
            system("rm -rf ".escapeshellarg($prefs['filename']));
            
            return $prefs['filename'] . ".zip";
        }
        elseif ($prefs['format'] === 'json') // Was a text file requested?
        {
            //mongoexport --db test --collection traffic --out traffic.json
            //return $this->_backup($prefs);
        }
                
        //$backup = $this->dbutil->backup($prefs);
        //$this->load->helper('file');
        //write_file($filename, $backup);
        
        //print "<pre>cmd:$cmd return:$return\n\n"; print_r($output); exit;
    }
}