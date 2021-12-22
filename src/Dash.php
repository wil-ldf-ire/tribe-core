<?php
/**
 * functions start with push_, pull_, get_, do_ or is_
 * push_ is to save to database
 * pull_ is to pull from database, returns 1 or 0, saves the output array in $last_data
 * get_ is to get usable values from functions
 * do_ is for action that doesn't have a database push or pull
 * is_ is for a yes/no answer
 */

namespace Wildfire\Core;

use Wildfire\Core\Init;
use Wildfire\Core\MySQL;

class Dash extends Init {
	public static $last_error = null; //array of error messages
	public static $last_info = null; //array of info messages
	public static $last_data = null; //array of data to be sent for display
	public static $last_redirect = null; //redirection url
	public $statusCode = null; // to set server response code
	public static $ignored_keys;

	public function __construct()
	{
		self::$ignored_keys = ['type', 'function', 'class', 'slug', 'id', 'updated_on', 'created_on', 'user_id', 'files_descriptor', 'password_md5', 'role_slug', 'mysql_activity_log'];
	}

	public function get_last_error()
	{
		if (count(dash::$last_error ?? [])) {
			$op = implode('<br>', dash::$last_error);
			dash::$last_error = array();
			return $op;
		} else {
			return '';
		}
	}

	public function get_last_info()
	{
		if (count(dash::$last_info ?? [])) {
			$op = implode('<br>', dash::$last_info);
			dash::$last_info = array();
			return $op;
		} else {
			return '';
		}
	}

	public function get_last_data()
	{
		$arr = dash::$last_data;
		dash::$last_data = array();
		return $arr;
	}

	public function get_last_redirect()
	{
		$r = dash::$last_redirect;
		dash::$last_redirect = '';
		return $r;
	}

	public function get_next_id()
	{
		$sql = new MySQL();
		$q = $sql->executeSQL("SELECT `id` FROM `data` WHERE 1 ORDER BY `id` DESC LIMIT 0,1");
		return ($q[0]['id'] + 1);
	}

	public function do_delete(array $post = []): bool
	{
		$sql = new MySQL();
		$types = Init::$types;

		$role_slug = $this->get_content_meta($post['id'], 'role_slug');
		$role_slug = $role_slug ? "&role=$role_slug" : '';

		if ($types['webapp']['soft_delete_records']) {
			$type = $this->get_content_meta($post['id'], 'type');
			$this->push_content_meta($post['id'], 'deleted_type', $type);
			$this->push_content_meta($post['id'], 'type', 'deleted_record');
		} else {
			$q = $sql->executeSQL("DELETE FROM data WHERE id={$post['id']}");
		}

		dash::$last_redirect = "/admin/list?type={$post['type']}$role_slug";

		return true;
	}

	public function get_ids_by_search_query($query)
	{
		$sql = new MySQL();
		return $sql->executeSQL("SELECT `id` FROM `data` WHERE LOWER(`content`->'$.view_searchable_data') LIKE '%" . strtolower(urldecode($query)) . "%' && `content_privacy`='public' GROUP BY `id` LIMIT 0,25");
	}

	public function push_content($post)
	{
		$sql = new MySQL();
		$auth = new \Wildfire\Auth\Auth;
		$currentUser = $auth->getCurrentUser() ?: [ 'name' => null, 'id' => null, 'slug' => null];
		$types = self::$types;
		$updated_on = time();
		$posttype = $post['type'];
		$is_new_record = false;
		$do_write_log = $types['webapp']['display_activity_log'] ?? false;

		$title_data = $this->get_type_title_data($post);
		$title_slug = $title_data['slug'];
		$title_unique = $title_data['unique'];

		$post['view_searchable_data'] = '';
		foreach ($types[$posttype]['modules'] as $module) {
			//password md5 handling is a tricky game
			//connected to admin/edit.php
			//$this->get_content can mess up passwords
			if ($module['input_type'] == 'password') {
				$password_slug = $module['input_slug'];
				$password_slug_md5 = $module['input_slug'] . '_md5';

				if ($post[$password_slug] && !$post[$password_slug_md5]) {
					if ($post['id']) {
						//while importing from get_content function
						$post[$password_slug] = $post[$password_slug];
						$post[$password_slug_md5] = $post[$password_slug];
					} else {
						//for new entries
						$post[$password_slug] = md5($post[$password_slug]);
						$post[$password_slug_md5] = $post[$password_slug];
					}
				} elseif ($post[$password_slug] && (md5($post[$password_slug]) != $post[$password_slug_md5])) {
					//post edit, password changed
					$post[$password_slug] = md5($post[$password_slug]);
					$post[$password_slug_md5] = $post[$password_slug];
				} elseif ($post[$password_slug_md5]) {
					//post edit, when password unchanged
					$post[$password_slug] = $post[$password_slug_md5];
					$post[$password_slug_md5] = $post[$password_slug_md5];
				}
			}

			if ($module['view_searchable'] && in_array($post['type'], $types['webapp']['searchable_types']) && $post['content_privacy'] == 'public') {
				$slug = $module['input_slug'];
				if (is_array($post[$slug])) {
					$post['view_searchable_data'] .= implode(' ', array_map('strip_tags', $post[$slug])) . ' ';
				} else {
					$post['view_searchable_data'] .= strip_tags($post[$slug]) . ' ';
				}
			}

			//change var_type if available, before saving to database
			if ($module['var_type']) {
				$slug = $module['input_slug'];
				if ($module['var_type'] == 'int') {
					$post[$slug] = (int) $post[$slug];
				} else if ($module['var_type'] == 'float') {
					$post[$slug] = (float) $post[$slug];
				} else if ($module['var_type'] == 'bool') {
					$post[$slug] = (bool) $post[$slug];
				}

			}
		}

		if ($title_unique) {
			$q = $sql->executeSQL("SELECT `id` FROM `data` WHERE `type`='" . $post['type'] . "' && `content`->'$." . $title_slug . "'='" . mysqli_real_escape_string($sql->databaseLink, $post[$title_slug]) . "' ORDER BY `id` DESC LIMIT 0,1");

			if ($q[0]['id'] && $post['id'] != $q[0]['id']) {
				dash::$last_error[] = 'Either the title is left empty or the same title already exists in ' . $types[$posttype]['plural'];
				return 0;
			}
		}

		if (!trim($post['slug']) || $post['slug_update']) {
			$post['slug'] = dash::do_slugify($post[$title_slug], $title_unique);
			unset($post['slug_update']);
		}

		if (!trim($post['id'])) {
			$sql->executeSQL("INSERT INTO `data` (`created_on`) VALUES ('$updated_on')");
			$post['id'] = $sql->lastInsertID();
			$is_new_record = true;
		}

		if ($post['wp_import']) {
			$sql->executeSQL("INSERT INTO `data` (`id`, `created_on`) VALUES ('" . $post['id'] . "', '$updated_on')");
		}

		// get keys which were updated during this operation
		if ($do_write_log) {
			$old_record = \json_decode($this->get_content_meta($post['id'], 'content'), 1);
			$diff = \array_diff($post, $old_record);
		}

		$sql->executeSQL("UPDATE `data` SET `content`='" . mysqli_real_escape_string($sql->databaseLink, json_encode($post)) . "', `updated_on`='$updated_on' WHERE `id`='" . $post['id'] . "'");
		$id = $post['id'];

		if (!trim($post['view_searchable_data'])) {
			$this->push_content_meta($post['id'], 'view_searchable_data');
		}

		dash::$last_info[] = 'Content saved.';
		dash::$last_data[] = array(
			'id' => $id,
			'slug' => $post['slug'],
			'updated_on' => $updated_on,
			'url' => BASE_URL . "/{$post['type']}/{$post['slug']}"
		);

		// write log for this operation
		if ($do_write_log) {
			$skip_log = false;

			if ($is_new_record) {
				$log_message = "created this record";
				$log_meta = [
					'created_on' => $updated_on,
					'created_by' => $currentUser['slug'],
					'user_id' => $currentUser['slug']
				];
			} else if (\sizeof($diff)) {
				$log_meta = [
					'updated_on' => $updated_on,
					'updated_by' => $currentUser['slug'],
					'user_id' => $currentUser['slug']
				];
				$log_message = "updated";

				$i = 0;
				foreach ($diff as $key => $value) {
					$log_message .= $i ? ',' : '';
					$log_message .= " &#39;$key&#39; to &#39;$value&#39;";
					$i++;
				}
			} else {
				$skip_log = true;
			}

			if (!$skip_log) {
				$this->pushLog($id, $currentUser, $log_message, $log_meta);
			}
		}

		return $id;
	}

	public function push_content_meta($id, $meta_key, $meta_value = ''): bool
	{
		$sql = new MySQL();
		$auth = new \Wildfire\Auth\Auth;
		$currentUser = $auth->getCurrentUser() ?: [ 'user' => null, 'id' => null, 'slug' => null ];

		if (!($id && $meta_key)) {
			return false;
		}

		if (!trim($meta_value)) { // to delete a key, when left empty
			$q = $sql->executeSQL("UPDATE data SET content = JSON_REMOVE(content, '$.$meta_key') WHERE id='$id'");
			$log_message = "deleted key $meta_key";
		} else {
			$meta_value = $sql->databaseLink->real_escape_string($meta_value);
			$q = $sql->executeSQL("UPDATE data SET content = JSON_SET(content, '$.$meta_key', '$meta_value') WHERE id='$id'");
			$log_message = "updated key &#39;$meta_key&#39; to &#39;$meta_value&#39;";
		}

		if (($types['webapp']['display_activity_log'] ?? false) && $meta_key != "view_searchable_data") {
			$timestamp = time();
			$log_meta = [
				'updated_on' => $timestamp,
				'updated_by' => $currentUser['slug'],
				'user_id' => $currentUser['slug']
			];

			$this->pushLog($id, $currentUser, $log_message, $log_meta);
		}

		return true;
	}

    /**
     * Method to get content from database in a flattened structure
     * @param  integer|array $identifier id of record or named array with type & slug keys
     * @return integer|array returns either 0 (for fail) or array of data
     */
	public function get_content($identifier)
	{
		$sql = new MySQL();

		// if $identifier is a csv of ids
		if (strpos($identifier, ',')) {
			$_ids = \explode(',', $identifier);
			$_ids = array_map('trim', $_ids);
		}

		if (isset($identifier['type'])) {
			/**
			 * interface to handle $identifier['type' && 'slug']
			 */
			$_where = "`slug` = '{$identifier['slug']}' AND `type` = '{$identifier['type']}'";

			// returns single row matching type and slug
			$sql_rows = $sql->executeSQL("SELECT * FROM data WHERE $_where ORDER BY id DESC LIMIT 0,1");
		} else if ((\is_array($identifier) && !isset($identifier[0]['type'])) || isset($_ids)) {
			/**
			 * interface to handle $q[*]['id] from get_all_ids
			 * or a csv containing ids
			 */

			// extracting ids and preparing them for sql "where in"
			if (!isset($_ids)) {
				$_ids = \array_column($identifier, 'id');
			}

			$_ids = json_encode($_ids);
			$_ids = \str_replace('[', '(', $_ids);
			$_ids = \str_replace(']', ')', $_ids);

			// returns multiple rows
			$sql_rows = $sql->executeSQL("SELECT * FROM data WHERE `id` IN $_ids ORDER BY `id` DESC");
		} else if (\is_array($identifier) && isset($identifier[0]['type'])) {
			// extract type & slug columns
			$_slugs = \array_column($identifier, 'slug');
			$_types = \array_column($identifier, 'type');

			// convert values to string
			$_slugs = json_encode($_slugs);
			$_types = json_encode($_types);

			// replace '[]' with '()'
			$_slugs = \str_replace('[', '(', $_slugs);
			$_slugs = \str_replace(']', ')', $_slugs);
			$_types = \str_replace('[', '(', $_types);
			$_types = \str_replace(']', ')', $_types);

			// returns multiple rows
			$sql_rows = $sql->executeSQL("SELECT * FROM data WHERE `slug` IN $_slugs AND `type` IN $_types ORDER BY `id` DESC");
		} else if (is_numeric($identifier)) {
			// return single row matching id
			$sql_rows = $sql->executeSQL("SELECT * FROM data WHERE `id`='$identifier' ORDER BY `id` DESC LIMIT 0,1");
			print_r($sql_rows);
		}

		// if no sql rows could be fetched, return a 0
        if (!$sql_rows[0]['id']) return 0;

		if (\sizeof($sql_rows) == 1) {
			$final_response = $sql->cleanUpQueryResponse($sql_rows);
		} else {
			// storing data as $final_response['id'] = $decoded_record;
			foreach ($sql_rows as $_result) {
				$_temp = $sql->cleanUpQueryResponse($_result);
				$final_response[$_temp['id']] = $_temp;
			}
		}
		return $final_response;
	}

	/**
	 * cherry pick key(s) from content based on match condition
	 *
	 * @param integer|array $search can either be an id or associative array with 'type' and 'slug' keys
	 * @param string $meta_key csv to select from table
	 */
	public function get_content_meta($search, string $meta_key)
	{
		$sql = new MySQL();
		$keys = \explode(',', $meta_key);
		$qry = '';

		foreach ($keys as $i => $key) {
			$qry .= ($i != 0) ? ',' : '';
			$qry .= $sql->validateKeyWithSchema($key);
		}

		if (\is_array($search)) {
			$search = "`slug`='{$search['slug']}' AND `type`='{$search['type']}'";
		} else {
			$search = "`id`='${search}'";
		}

		$q = $sql->executeSQL("SELECT {$qry} FROM `data` WHERE {$search} LIMIT 0,1");

		if (\strpos($meta_key, ',')) {
			return $q[0];
		}

		return $q[0][$meta_key];
	}

    /**
     * Fetch db record based on id
     * @param  int    $id id of record in database
     * @return array     empty or non-empty (depening on status)
     */
    public function findById(int $id)
    {
        $sql = new MySQL();
		return $sql->getId($id);
    }

	public function fetch_content_title_array($slug, $column_key, $with_link = 1)
	{
		$sql = new MySQL();
		$types = self::$types;

		$q = $sql->executeSQL("SELECT `content`->'$.title' `title` FROM `data` WHERE `slug`='$slug' && `type`='$column_key'");
		if ($with_link) {
			return '<a href="' . BASE_URL . '/' . $column_key . '/' . $slug . '">' . json_decode($q[0]['title']) . '</a>';
		} else {
			return json_decode($q[0]['title']);
		}
	}

	public static function get_all_ids_count($type)
	{
		$sql = new MySQL();
		$currentUser = self::$currentUser;

		//user
		if (is_array($type)) {
			//accessible only to admins
			if ($currentUser['role'] != 'admin') {
				return 0;
			} else {
				$role_slug = $type['role_slug'];
				$type = $type['type'];
				$q = $sql->executeSQL("SELECT `id` FROM `data` WHERE `type`='$type' " . ($role_slug ? "&& `role_slug`='$role_slug'" : ""));
			}
		}

		//content
		else {
			$role_slug = '';
			if ($currentUser['role'] == 'admin') {
				$q = $sql->executeSQL("SELECT `id` FROM `data` WHERE `content_privacy`!='draft' && `type`='$type' " . ($role_slug ? "&& `role_slug`='$role_slug'" : ""));
			} else {
				$q = $sql->executeSQL("SELECT `id` FROM `data` WHERE (`content_privacy`='public' OR `user_id`='" . $currentUser['user_id'] . "') && `type`='$type' " . ($role_slug ? "&& `role_slug`='$role_slug'" : ""));
			}
		}

		return $sql->records;
	}

	/**
	 * @param mixed $type
	 * @param string $priority_field
	 * @param string $priority_order
	 * @param int $limit
	 * @param boolean $debug_show_sql_statement
	 * @return array
	 * @return int status
	 */
	public function get_all_ids(
		$type,
		$priority_field = 'id',
		$priority_order = 'DESC',
		$limit = '',
		$debug_show_sql_statement = 0
	)
	{
		$sql = new MySQL();
		$currentUser = self::$currentUser;

		if ($priority_field == 'id') {
			$priority = "$priority_field $priority_order";
		} else {
			$priority = "content->'$.$priority_field' IS NULL, content->'$.$priority_field' $priority_order, id DESC";
		}

		//user
		if (is_array($type)) {
			//accessible only to admins
			if ($currentUser['role'] != 'admin') {
				return 0;
			}

			$role_slug = $type['role_slug'];
			$type = $type['type'];

			$trans = [
				'@roleSlug' => $role_slug ? " AND role_slug='$role_slug'" : "",
				'@limit' => $limit ? " LIMIT $limit" : "",
			];

			$query = "SELECT id FROM data
                WHERE
                    type='$type'
                    @roleSlug
                    ORDER BY $priority
                    @limit
            ";

			$query = strtr($query, $trans);

			$q = $sql->executeSQL($query);
		} else {
			//content
			$role_slug = '';

			$trans = [
				'@roleSlug' => $role_slug ? " AND role_slug='$role_slug'" : "",
				'@limit' => $limit ? " LIMIT $limit" : "",
			];

			if (($currentUser['role'] ?? false) == 'admin') {
				$query = "SELECT id FROM data
                    WHERE
                        content_privacy!='draft'
                        AND
                        type='$type'
                        @roleSlug
                        ORDER BY $priority
                        @limit
                ";

				$query = strtr($query, $trans);

				$q = $sql->executeSQL($query);
			} else {
				$trans['@userId'] = isset($currentUser['user_id']) ? $currentUser['user_id'] : '';

				$query = "SELECT id FROM data
                    WHERE
                        content_privacy='public'
                        AND
                        type='$type'
                        @userId
                        @roleSlug
                        ORDER BY $priority
                        @limit
                ";

				$query = strtr($query, $trans);

				$q = $sql->executeSQL($query);
			}
		}

		if ($debug_show_sql_statement) {
			echo $query;
		}

		return $q;
	}

	public function get_ids($search_arr, $comparison = 'LIKE', $between = '||', $priority_field = 'id', $priority_order = 'DESC', $limit = '', $debug_show_sql_statement = 0)
	{
		$sql = new MySQL();
		if ($priority_field == 'id') {
			$priority = "`" . $priority_field . "` " . $priority_order;
		} else {
			$priority = "`content`->'$." . $priority_field . "' IS NULL, `content`->'$." . $priority_field . "' " . $priority_order . ", `id` DESC";
		}

		$frechr = array();
		$i = 0;
		if (!is_array($comparison)) {
			$comparisonr = array_fill(0, count($search_arr), $comparison);
		} else {
			$comparisonr = $comparison;
		}

		foreach ($search_arr as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $kv => $vv) {
					$frechr[] = "`content`->'$." . $kv . "' " . $comparisonr[$i] . " " . (trim($vv) ? "'" . $vv . "'" : "");
				}
			} else {
				$frechr[] = "`content`->'$." . $key . "' " . $comparisonr[$i] . " " . (trim($value) ? "'" . $value . "'" : "");
			}

			$i++;
		}

		$qry = "SELECT `id` FROM `data` WHERE `content_privacy`='public' AND " . join(' ' . $between . ' ', $frechr) . " ORDER BY " . $priority . ($limit ? " LIMIT " . $limit : "");
		$r = $sql->executeSQL($qry);
		if ($debug_show_sql_statement) {
			echo $qry;
		}

		return $r;
	}

	public function get_date_ids($publishing_date)
	{
		$sql = new MySQL();
		return $sql->executeSQL("SELECT `id` FROM `data` WHERE `content`->'$.publishing_date'='$publishing_date'");
	}

	public function do_slugify($string, $input_itself_is_unique = 0)
	{
		//size of slug should be less than 255 characters because of DB field, so 230 + length of uniqid()
		$slug = substr(strtolower(trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', ($string ? $string : 'untitled')))), 0, 230) . ($input_itself_is_unique ? '' : '-' . uniqid());
		return $slug;
	}

	public function do_unslugify($url_part)
	{
		return strtolower(trim(rawurlencode($url_part)));
	}

	public static function get_types($json_path)
	{
		$currentUser = self::$currentUser;

		$meta_types = json_decode('{
          "key_value_pair": {
            "slug": "key_value_pair",
            "name": "key-value pair",
            "plural": "key-value pairs",
            "description": "List of key-value pairs.",
            "disallow_editing": false,
            "modules": [
              {
                "input_slug": "title",
                "input_primary": true,
                "input_type": "text",
                "input_placeholder": "Enter remarks",
                "input_unique": false,
                "list_field": true,
                "list_searchable": true,
                "list_sortable": true
              },
              {
                "input_slug": "meta_key",
                "input_type": "text",
                "input_placeholder": "Meta Key"
              },
              {
                "input_slug": "meta_value",
                "input_type": "text",
                "input_placeholder": "Meta Value"
              }
            ]
          },
          "api_key_secret": {
            "slug": "key_value_pair",
            "name": "API key-secret pair",
            "plural": "API key-secret pairs",
            "description": "List of API key-secret pairs.",
            "disallow_editing": false,
            "modules": [
              {
                "input_slug": "title",
                "input_primary": true,
                "input_type": "text",
                "input_placeholder": "Enter remarks",
                "input_unique": false,
                "list_field": true,
                "list_searchable": true,
                "list_sortable": true
              },
              {
                "input_slug": "api_key",
                "input_type": "text",
                "input_placeholder": "API Key"
              },
              {
                "input_slug": "api_secret",
                "input_type": "text",
                "input_placeholder": "API Secret"
              }
            ]
          }
        }', true);

		$types_json = \json_decode(\file_get_contents($json_path), true);
		if (!$types_json) {
			die("<em><b>Error:</b> types</em> validation failed");
		}

		$types = array_merge($types_json, $meta_types);
		foreach ($types as $key => $type) {
			$type_slug = $type['slug'] ?? 'undefined';

			if (!($type_slug == 'user' || $type_slug == 'webapp')) {
				$type_key_modules = $types[$key]['modules'] ?? [];

				if (!in_array('content_privacy', array_column($type_key_modules, 'input_slug'))) {
					if (($currentUser['role'] ?? false) == 'admin') {
						$content_privacy_json = '{
					        "input_slug": "content_privacy",
					        "input_placeholder": "Content privacy",
					        "input_type": "select",
					        "input_options": [
					          {"slug":"public", "title":"Public link"},
					          {"slug":"private", "title":"Private link"},
					          {"slug":"pending", "title":"Submit for moderation"},
					          {"slug":"draft", "title":"Draft"}
					        ],
					        "list_field": true,
					        "input_unique": false
					    }';
					} else {
						$content_privacy_json = '{
					        "input_slug": "content_privacy",
					        "input_placeholder": "Content privacy",
					        "input_type": "select",
					        "input_options": [
					          {"slug":"pending", "title":"Submit for moderation"},
					          {"slug":"draft", "title":"Draft"}
					        ],
					        "list_field": true,
					        "input_unique": false
					    }';
					}
					$types[$key]['modules'][] = json_decode($content_privacy_json, true);
				}

				foreach ($types[$key]['modules'] as $module) {
					if (!isset($module['input_primary'])) {
						continue;
					}

					$types[$key]['primary_module'] = $module['input_slug'];
					break;
				}
			}
		}
		return $types;
	}

	public function get_type_title_data($post)
	{
		$sql = new MySQL();
		$types = self::$types;
		$posttype = $post['type'];

		if (!($post_id = $post['id'])) {
			$last_id = $sql->executeSQL("SELECT `id` FROM `data` ORDER BY `id` DESC LIMIT 0,1");
			$post_id = $last_id[0]['id'] + 1;
		}
		//foreach loop that breaks
		$i = 0;
		foreach ($types[$posttype]['modules'] as $module) {
			$title = array();
			if ($module['input_primary']) {
				$title_id = $i;
				$title['slug'] = $module['input_slug'] . (is_array($module['input_lang']) ? '_' . $module['input_lang'][0]['slug'] : '');
				$title['primary'] = $module['input_primary'];
				$title['unique'] = $module['input_unique'];
				break;
			}

			$i++;
		}
		return $title;
	}

	public function push_wp_posts($type = 'story', $meta_vars = array(), $max_records = 0, $overwrite = 0)
	{
		$sql = new MySQL();
		$i = 0;

		$q = $sql->executeSQL("SELECT * FROM `wp_posts` WHERE `post_status` LIKE 'publish' AND (`post_type` LIKE 'page' OR `post_type` LIKE 'post') ORDER BY `ID` ASC");

		foreach ($q as $r) {
			if ($overwrite || !$this->get_content_meta($r['ID'], 'slug')) {
				$post = $post_wp = array();
				$post['wp_import'] = 1;
				$post['id'] = $r['ID'];
				$post['type'] = $type;
				$post['title'] = $r['post_title'];
				$post['body'] = $r['post_content'];
				$post['slug'] = $r['post_name'];
				$post['content_privacy'] = 'public';
				$post['publishing_date'] = substr($r['post_date'], 0, 10);

				if ($r['post_parent']) {
					$mv = $sql->executeSQL("SELECT `post_name` FROM `wp_posts` WHERE `ID`='" . $r['post_parent'] . "'");
					$post_wp['post_parent'] = $mv[0]['post_name'];
				}

				foreach ($meta_vars as $var) {
					$iv = $sql->executeSQL("SELECT `meta_value` FROM `wp_postmeta` WHERE `post_id`='" . $r['ID'] . "' && `meta_key`='$var'");
					if ($iv[0]['meta_value']) {
						$ivts = unserialize($iv[0]['meta_value']);
						if (!$ivts) {
							$iid = $iv[0]['meta_value'];
							if (is_numeric($iid)) {
								$mv = $sql->executeSQL("SELECT `post_name` FROM `wp_posts` WHERE `ID`='$iid'");
								$post_wp[$var] = $mv[0]['post_name'];
							} else {
								$post_wp[$var] = $iid;
							}
						} else {
							foreach ($ivts as $iid) {
								if (is_numeric($iid)) {
									$mv = $sql->executeSQL("SELECT `post_name` FROM `wp_posts` WHERE `ID`='$iid'");
									$post_wp[$var][] = $mv[0]['post_name'];
								} else {
									$post_wp[$var][] = $iid;
								}
							}
						}
					}
				}

				$cv = $sql->executeSQL("SELECT `guid` FROM `wp_posts` WHERE `post_parent` != 0 AND `guid` LIKE '%wp-content/uploads%' AND `post_type` LIKE 'attachment' AND `post_status` LIKE 'inherit' AND `guid` != '' AND `post_parent`='" . $r['ID'] . "' ORDER BY `ID` DESC");
				$post['files'] = array();
				foreach ($cv as $file) {
					$ext = strtolower(pathinfo($file['guid'], PATHINFO_EXTENSION));
					if ($ext == 'png' || $ext == 'jpg' || $ext == 'jpeg' || $ext == 'gif') {
						$post['files'][] = $file['guid'];
						$post['cover_media'] = $file['guid'];
					} else {
						$post['files'][] = $file['guid'];
					}
				}

				$post['wp_post_data'] = serialize($post_wp);
				//$post=update_wp_post_data($post);

				$this->push_content($post);

				$i++;
				if ($max_records && $i >= $max_records) {
					break;
				}
			}
		}
	}

	public function get_unique_user_id()
	{
		$sql = new MySQL();
		$bytes = strtoupper(bin2hex(random_bytes(3)));

		$q = $sql->executeSQL("SELECT id FROM data WHERE user_id='$bytes' ORDER BY id DESC LIMIT 0,1");

		if ($q && $q[0]['id']) {
			return $this->get_unique_user_id();
		} else {
			return $bytes;
		}
	}

	public function do_shell_command($cmd)
	{
		ob_start();
		passthru($cmd);
		$tml = ob_get_contents();
		ob_end_clean();
		return $tml;
	}

	public function get_upload_dir_path()
	{
		return TRIBE_ROOT . '/uploads/' . date('Y') . '/' . date('m-F') . '/' . date('d-D');
	}

	public function get_upload_dir_url()
	{
		return BASE_URL . '/uploads/' . date('Y') . '/' . date('m-F') . '/' . date('d-D');
	}

	public function get_uploader_path()
	{
		$folder_path = 'uploads/' . date('Y') . '/' . date('m-F') . '/' . date('d-D');
		if (!is_dir(TRIBE_ROOT . '/' . $folder_path)) {
			mkdir(TRIBE_ROOT . '/' . $folder_path, 0755, true);
		}

		return array('upload_dir' => TRIBE_ROOT . '/' . $folder_path, 'upload_url' => BASE_URL . '/' . $folder_path);
	}

	public function get_uploaded_image_in_size($file_url, $thumbnail = 'md')
	{
		if (preg_match('/\.(gif|jpe?g|png)$/i', $file_url)) {
			$file_arr = array();
			$file_parts = explode('/', $file_url);
			$file_parts = array_reverse($file_parts);
			$filename = urldecode($file_parts[0]);
			if (strlen($file_parts[1]) == 2) {
				$year = $file_parts[4];
				$month = $file_parts[3];
				$day = $file_parts[2];
				$size = $file_parts[1];
			} else {
				$year = $file_parts[3];
				$month = $file_parts[2];
				$day = $file_parts[1];
			}

			if (file_exists(TRIBE_ROOT . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . $thumbnail . '/' . substr(escapeshellarg($filename), 1, -1))) {
				$file_arr['path'] = TRIBE_ROOT . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . $thumbnail . '/' . substr(escapeshellarg($filename), 1, -1);
				$file_arr['url'] = BASE_URL . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . $thumbnail . '/' . rawurlencode($filename);
			}
			else {
				$file_arr['path'] = TRIBE_ROOT . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . substr(escapeshellarg($filename), 1, -1);
				$file_arr['url'] = BASE_URL . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . rawurlencode($filename);
			}

			return $file_arr;
		} else {
			return false;
		}
	}

	public function get_uploaded_file_versions($file_url, $thumbnail = 'xs')
	{

		$file_arr = array();
		$file_parts = explode('/', $file_url);
		$file_parts = array_reverse($file_parts);
		$filename = urldecode($file_parts[0]);

		if (strlen($file_parts[1]) == 2) {
			$year = $file_parts[4];
			$month = $file_parts[3];
			$day = $file_parts[2];
			$size = $file_parts[1];
		} else {
			$year = $file_parts[3];
			$month = $file_parts[2];
			$day = $file_parts[1];
		}

		$file_arr['path']['source'] = TRIBE_ROOT . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . substr(escapeshellarg($filename), 1, -1);
		$file_arr['url']['source'] = BASE_URL . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . rawurlencode($filename);

		if (preg_match('/\.(gif|jpe?g|png)$/i', $file_url)) {
			$sizes = array('xl', 'lg', 'md', 'sm', 'xs');

			foreach ($sizes as $size) {
				if (file_exists(TRIBE_ROOT . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . $size . '/' . $filename)) {
					$file_arr['path'][$size] = TRIBE_ROOT . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . $size . '/' . substr(escapeshellarg($filename), 1, -1);
					$file_arr['url'][$size] = BASE_URL . '/uploads/' . $year . '/' . $month . '/' . $day . '/' . $size . '/' . rawurlencode($filename);
				}
				else {
					$file_arr['path'][$size] = $file_arr['path']['source'];
					$file_arr['url'][$size] = $file_arr['url']['source'];
				}
			}

			if (file_exists($file_arr['path'][$thumbnail])) {
				$file_arr['url']['thumbnail'] = $file_arr['url'][$thumbnail];
				$file_arr['path']['thumbnail'] = $file_arr['path'][$thumbnail];
			} else {
				$file_arr['url']['thumbnail'] = $file_arr['url']['source'];
				$file_arr['path']['thumbnail'] = $file_arr['path']['source'];
			}
		}

		return $file_arr;
	}

	public function get_dir_url()
	{
		return str_replace(TRIBE_ROOT, BASE_URL, getcwd());
	}

	public function do_upload_file_from_url($url)
	{
		if ($url ?? false) {
			$path = $this->get_uploader_path();

			$file_name = time() . '-' . basename($url);
			$wf_uploads_path = $path['upload_dir'] . '/' . $file_name;
			$wf_uploads_url = $path['upload_url'] . '/' . $file_name;

			if (copy($url, $wf_uploads_path)) {
				return $wf_uploads_url;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * Undocumented function
	 *
	 * @param integer $id
	 * @param array $user requires 'id', 'name' and 'slug'
	 * @param string|null $msg
	 * @param array $log_meta requires 'created/updated_on', 'created/updated_by', 'user_id'
	 * @return void
	 */
	public function pushLog(int $id, array $user, string $msg = null, array $log_meta)
	{
		$sql = new MySQL;

		$data = json_encode([
			'user_name' => $user['name'],
			'user_id' => $user['id'],
			'time' => date('d/m/Y H:i:s'),
			'message' => $msg,
			'log_meta' => $log_meta
		]);

		$sql->executeSQL("UPDATE data SET content = JSON_ARRAY_APPEND(content, '$.mysql_activity_log', '$data') WHERE id=$id");
	}

	public function checkFileUploadName(string $filename): bool
	{
		return (bool) ((preg_match("`^[-0-9A-Z_\.]+$`i", $filename)) ? true : false);
	}

	public function checkFileUploadNameLength(string $filename): bool
	{
		return (bool) ((mb_strlen($filename,"UTF-8") > 225) ? true : false);
	}
}
