<?php defined('BASEPATH') || exit('No direct script access allowed');
/**
 * Bonfire
 *
 * An open source project to allow developers to jumpstart their development of
 * CodeIgniter applications
 *
 * @package   Bonfire
 * @author    Bonfire Dev Team
 * @copyright Copyright (c) 2011 - 2014, Bonfire Dev Team
 * @license   http://opensource.org/licenses/MIT
 * @link      http://cibonfire.com
 * @since     Version 1.0
 * @filesource
 */

/**
 * Roles Settings Context
 *
 * Allows the management of the Bonfire roles.
 *
 * @package    Bonfire\Modules\Roles\Controllers\Settings
 * @author     Bonfire Dev Team
 * @link       http://cibonfire.com/docs/bonfire/roles_and_permissions
 *
 */
class Settings extends Admin_Controller
{
    private $permissionContext = 'Site.Settings.View';
    private $permissionCreate = 'Bonfire.Roles.Add';
    private $permissionEdit = 'Bonfire.Roles.Manage';
    private $permissionView = 'Bonfire.Roles.View';

    /**
     * Setup the required permissions and load required classes
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->auth->restrict($this->permissionContext);
        $this->auth->restrict($this->permissionView);

        $this->load->model('role_model');
        $this->load->model('role_odm_model');
        $this->lang->load('roles');

        Assets::add_module_css('roles', 'css/settings.css');

        Assets::add_js('codeigniter-csrf.js');
        Assets::add_module_js('roles', 'jquery.tablehover.pack.js');
        Assets::add_module_js('roles', 'js/settings.js');

        // For the render_search_box()
        $this->load->helper('ui/ui');

        Template::set_block('sub_nav', 'settings/_sub_nav');
    }

    /**
     * Display a list of all roles
     *
     * @return void
     */
    public function index()
    {
        // Get User Counts
        /*
        Template::set('deleted_users', $this->user_odm_model->count_all(true));
        Template::set('role_counts', $this->user_odm_model->count_by_roles());
        Template::set('total_users', $this->user_odm_model->count_all());
        */
        Template::set('deleted_users', $this->user_odm_model->count_all(true));
        Template::set('role_counts', $this->user_odm_model->count_by_roles());
        Template::set('total_users', $this->user_odm_model->count_all());

        $roles = $this->role_odm_model->find_all_by('deleted', null);
        
        //Template::set('roles', $this->role_model->where('deleted', 0)->find_all());
        Template::set('roles', $roles);
        
        Template::set('toolbar_title', lang('role_manage'));
        Template::render();
    }

    /**
     * Create a new role in the database
     *
     * @return void
     */
    public function create()
    {
        $this->auth->restrict($this->permissionCreate);

        if (isset($_POST['save'])) {
            if ($this->saveRole()) {
                Template::set_message(lang('role_create_success'), 'success');
                if (! empty($this->error)) {
                    Template::set_message(lang('role_create_error') . $this->error, 'error');
                }
                redirect(SITE_AREA . '/settings/roles');
            }

            if (! empty($this->role_model->error)) {
                Template::set_message(lang('role_create_error') . $this->role_model->error, 'error');
            }
        }

        if (! class_exists('Contexts', false)) {
            $this->load->library('ui/contexts');
        }

        Template::set_view('settings/role_form');
        Template::set('contexts', Contexts::getContexts(true));
        Template::set('toolbar_title', 'Create New Role');

        Template::render();
    }

    /**
     * Edit a role record
     *
     * @return void
     */
    public function edit()
    {
        $this->auth->restrict($this->permissionEdit);
        $id = $this->uri->segment(5);
        if (empty($id)) {
            Template::set_message(lang('role_invalid_id'), 'error');
            redirect(SITE_AREA . '/settings/roles');
        }

        if (isset($_POST['save'])) {
            if ($this->saveRole('update', $id)) {
                Template::set_message(lang('role_edit_success'), 'success');
                redirect(SITE_AREA . '/settings/roles');
            }
            if (! empty($this->role_odm_model->error)) {
                Template::set_message(lang('role_edit_error') . $this->role_model->error, 'error');
            }
        } elseif (isset($_POST['delete'])) {
            if ($this->role_odm_model->delete($id)) {
                Template::set_message(lang('role_delete_success'), 'success');
                redirect(SITE_AREA . '/settings/roles');
            }
            if (! empty($this->role_model->error)) {
                Template::set_message(lang('role_delete_error') . $this->role_model->error, 'error');
            }
        }

        if (! class_exists('Contexts', false)) {
            $this->load->library('ui/contexts');
        }

        $title = lang('bf_action_edit') . ' ' . lang('matrix_role');
        $role = $this->role_odm_model->find($id);
        $contexts = Contexts::getContexts(true);
        
        Template::set_view('settings/role_form');
        Template::set('contexts', $contexts);
        Template::set('role', $role);
        Template::set('toolbar_title', isset($role->role_name) ? "{$title}: {$role->role_name}" : $title);

        Template::render();
    }

    //--------------------------------------------------------------------
    // !HMVC METHODS
    //--------------------------------------------------------------------

    /**
     * Build the matrix for display in the role permissions form.
     *
     * @return string The table(s) of settings, ready to be used in a form.
     */
    public function matrix()
    {
        $id = $this->uri->segment(5);
        $role = $this->role_odm_model->find($id);

        // ID is empty for a new role, so permissions won't be assigned, yet.
        if ($id == 0) {
            return '<div class="alert alert-info">' . lang('role_new_permission_message') . '</div>';
        }

        $auth_failed = '';
        $domains = '';

        // Verify role has permission to modify this role's permissions.
        if (! $this->auth->has_permission('Permissions.' . ucwords($role->role_name) . '.Manage')) {
            $auth_failed = lang('matrix_auth_fail');
        } else {
            $permissions_full = $this->role_odm_model->get_all_permissions();
            $role_permissions = $role->role_permissions;

            $template = array();
            foreach ($permissions_full as $key => $perm) {
                $template[$perm->name]['perm_id'] = $perm->id;
                $template[$perm->name]['value'] = 0;
                //if (isset($role_permissions[$perm->id])) {
                if($perm->id == $role->hasPermission($perm->id)) {
                    $template[$perm->name]['value'] = 1;
                }
            }
//print "<pre>template:"; print_r($template); exit;

            // Extract the pieces from each permission.
            $domains = array();
            foreach ($template as $key => $value) {
                list($domain, $name, $action) = explode('.', $key);

                // Add it to the domain if it's not already there.
                if (! empty($domain) && ! array_key_exists($domain, $domains)) {
                    $domains[$domain] = array();
                }

                // Add the preference to the domain array.
                if (isset($domains[$domain][$name])) {
                    $domains[$domain][$name][$action] = $value;
                } else {
                    $domains[$domain][$name] = array(
                        $action => $value
                    );
                }

                // Store the actions separately to build the table header.
                if (! isset($domains[$domain]['actions'])) {
                    $domains[$domain]['actions'] = array();
                }

                if (! in_array($action, $domains[$domain]['actions'])) {
                    $domains[$domain]['actions'][] = $action;
                }
            }
        }

        // Build the table(s) in the view to make things a little clearer,
        // and return it!
        return $this->load->view(
            'settings/matrix',
            array('domains' => $domains, 'authentication_failed' => $auth_failed),
            true
        );
    }

    //--------------------------------------------------------------------
    // !PRIVATE METHODS
    //--------------------------------------------------------------------

    /**
     * Save the role record to the database.
     *
     * @param string  $type The type of save operation (insert or edit).
     * @param integer $id   The record ID in the case of edit (ignored on insert).
     *
     * @return boolean
     */
    private function saveRole($type = 'insert', $id = 0)
    {
        $this->form_validation
                ->set_rules($this->role_odm_model->get_validation_rules($type));
        if ($this->form_validation->run() === false) {
            return false;
        }

        // Grab the permissions and role name from the POST vars, if available.
        $permissions = $this->input->post('role_permissions');
        $roleName = $this->input->post('role_name');
        $data = $this->role_odm_model->prep_data($this->input->post());

        if ($type == 'insert') {
            $id = $this->role_odm_model->insert($data);
            $return = !empty($id);
        } elseif ($type == 'update') {
            $return = $this->role_odm_model->update($id, $data);
        }

        if (! $return) {
            return $return;
        }

        // Add a new management permission for the role.
        $new_perm_name = 'Permissions.' . ucwords($roleName) . '.Manage';

        $this->load->model('permissions/permission_odm_model');
        
        $add_perm = array(
            'name'        => $new_perm_name,
            'description' => "To manage the access control permissions for the {$roleName} role.",
            'status'      => 'active'
        );

        if ($type == 'insert') {
            $permissionId = $this->permission_odm_model->insert($add_perm);

            if (! $permissionId) {
                $this->error = 'There was an error creating the ACL permission.';
                if (! empty($this->permission_odm_model->error)) {
                    $this->error .= " {$this->permission_odm_model->error}";
                }
            } else {
                // Give current_role and admin new Manage permission.
                
                //get admin id:
                $admin_role_id = $this->role_odm_model->find_by('role_name', 'Administrator');
                $roleIds = array($admin_role_id);
                
                if (class_exists('auth')) {
                    $roleId = $this->auth->role_id();
                    if ($roleId != false) {
                        $rolesIds[] = $roleId;
                    }
                }

                $rolePermissions = array();
                foreach ($roleIds as $roleId) {
                    print "<pre>roleid:$roleId";
                    //get role document and update:
                    $role = $this->role_odm_model->find($roleId);
                    if(!empty($role->id)) {
                        $role->role_permissions[] = $permissionId;
                        $this->role_odm_model->save($role);
                    }
                }
            }
        } else {
            // Update
            //
            // Grab the name of the role being updated.
            $current_name = $this->role_odm_model->find($id)->role_name;

            $current_permission = 'Permissions.' . ucwords($current_name) . '.Manage';
            // Update the permission name.
            $permission = $this->permission_odm_model->find_by('name', $current_permission);
            if(!empty($permission->id)) {
                $permission->name = $new_perm_name;
            }
            else {
                $permission = $this->permission_odm_model->insert($add_perm, 'object');
            }
            
            $this->permission_odm_model->save($permission);
            
/*            
 * 
            $this->permission_odm_model->update_where(
                'name',
                'Permissions.' . ucwords($current_name) . '.Manage',
                array('name' => $new_perm_name)
            );
 * 
 * 
 */
        }

        // Reset validation so the role_permissions model can use it.
        //$this->form_validation->reset_validation();

        // Save the permissions.
        /*
        if ($permissions
            && ! $this->role_permission_model->set_for_role($id, $permissions)
           ) {
            $this->error = 'There was an error saving the permissions.';
        }
         * 
         */

        return $return;
    }

    /**
     * Create a real-time modifiable summary table of all roles and permissions
     *
     * @return void
     */
    public function permission_matrix()
    {
        // For the permission matrix
        $this->load->helper('inflector');

        Template::set(
            'matrix_permissions',
            $this->permission_model->select('permission_id, name')
                                   ->order_by('name')
                                   ->find_all()
        );
        Template::set(
            'matrix_roles',
            $this->role_model->select('role_id, role_name')
                             ->where('deleted', 0)
                             ->find_all()
        );

        $role_permissions = $this->role_permission_model->find_all_role_permissions();
        foreach ($role_permissions as $rp) {
            $current_permissions[] = "{$rp->role_id},{$rp->permission_id}";
        }

        Template::set('matrix_role_permissions', $current_permissions);
        Template::set("toolbar_title", lang('matrix_header'));

        Template::set_view('settings/permission_matrix');
        Template::render();
    }

    /**
     * Update the role_permissions table.
     *
     * @return void
     */
    public function matrix_update()
    {
        // Disable the profile for AJAX response
        $this->output->enable_profiler(false);

        $pieces = explode(',', $this->input->post('role_perm'));

        if (! $this->auth->has_permission('Permissions.' . $this->role_model->find((int)$pieces[0])->role_name . '.Manage')) {
            $this->output->set_output(lang("matrix_auth_fail"));

            return;
        }

        // A box was checked
        if ($this->input->post('action') == 'true') {
            if (is_numeric($this->role_permission_model->create_role_permissions($pieces[0], $pieces[1]))) {
                $msg = lang("matrix_insert_success");
            } else {
                $msg = lang("matrix_insert_fail") . $this->role_permission_model->error;
            }
        } else {
            // A box was unchecked
            if ($this->role_permission_model->delete_role_permissions($pieces[0], $pieces[1])) {
                $msg = lang("matrix_delete_success");
            } else {
                $msg = lang("matrix_delete_fail"). $this->role_permission_model->error;
            }
        }

        $this->output->set_output($msg);
    }
    

}
