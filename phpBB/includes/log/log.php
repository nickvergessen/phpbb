<?php
/**
*
* @package phpbb_log
* @copyright (c) 2012 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* This class is used to add entries into the log table.
*
* @package phpbb_log
*/
class phpbb_log implements phpbb_log_interface
{
	/**
	* Keeps the total log count of the last call to get_logs()
	*/
	private $logs_total;

	/**
	* Keeps the offset of the last valid page of the last call to get_logs()
	*/
	private $logs_offset;

	/**
	* The table we use to store our logs.
	*/
	private $log_table;

	private $db;

	private $dispatcher;

	/**
	* Constructor
	*
	* @param	string	$log_table		The table we use to store our logs
	*/
	public function __construct($log_table, dbal $db, phpbb_event_dispatcher $dispatcher = null)
	{
		$this->log_table = $log_table;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
	}

	/**
	* Adds a log to the database
	*
	* {@inheritDoc}
	*/
	public function add($mode, $user_id, $log_ip, $log_operation, $log_time = false, $additional_data = array())
	{
		if ($log_time == false)
		{
			$log_time = time();
		}

		$sql_ary = array(
			'user_id'		=> $user_id,
			'log_ip'		=> $log_ip,
			'log_time'		=> $log_time,
			'log_operation'	=> $log_operation,
		);

		switch ($mode)
		{
			case 'admin':
				$sql_ary += array(
					'log_type'		=> LOG_ADMIN,
					'log_data'		=> (!empty($additional_data)) ? serialize($additional_data) : '',
				);
			break;

			case 'mod':
				$forum_id = (int) $additional_data['forum_id'];
				unset($additional_data['forum_id']);
				$topic_id = (int) $additional_data['topic_id'];
				unset($additional_data['topic_id']);
				$sql_ary += array(
					'log_type'		=> LOG_MOD,
					'forum_id'		=> $forum_id,
					'topic_id'		=> $topic_id,
					'log_data'		=> (!empty($additional_data)) ? serialize($additional_data) : '',
				);
			break;

			case 'user':
				$reportee_id = (int) $additional_data['reportee_id'];
				unset($additional_data['reportee_id']);

				$sql_ary += array(
					'log_type'		=> LOG_USERS,
					'reportee_id'	=> $reportee_id,
					'log_data'		=> (!empty($additional_data)) ? serialize($additional_data) : '',
				);
			break;

			case 'critical':
				$sql_ary += array(
					'log_type'		=> LOG_CRITICAL,
					'log_data'		=> (!empty($additional_data)) ? serialize($additional_data) : '',
				);
			break;

			default:
				if ($this->dispatcher != null)
				{
					$vars = array('mode', 'user_id', 'log_ip', 'log_operation', 'log_time', 'additional_data', 'sql_ary');
					extract($this->dispatcher->trigger_event('core.add_log_case', $vars));
				}

				// We didn't find a log_type, so we don't save it in the database.
				if (!isset($sql_ary['log_type']))
				{
					return false;
				}
		}

		if ($this->dispatcher != null)
		{
			$vars = array('mode', 'user_id', 'log_ip', 'log_operation', 'log_time', 'additional_data', 'sql_ary');
			extract($this->dispatcher->trigger_event('core.add_log', $vars));
		}

		$this->db->sql_query('INSERT INTO ' . $this->log_table . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary));

		return $this->db->sql_nextid();
	}

	/**
	* Grab the logs from the database
	*
	* {@inheritDoc}
	*/
	public function get_logs($mode, $count_logs = true, $limit = 0, $offset = 0, $forum_id = 0, $topic_id = 0, $user_id = 0, $log_time = 0, $sort_by = 'l.log_time DESC', $keywords = '')
	{
		global $user, $auth, $phpEx, $phpbb_root_path, $phpbb_admin_path;

		$this->logs_total = 0;
		$this->logs_offset = $offset;

		$topic_id_list = $reportee_id_list = array();

		$profile_url = (defined('IN_ADMIN')) ? append_sid("{$phpbb_admin_path}index.$phpEx", 'i=users&amp;mode=overview') : append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=viewprofile');

		switch ($mode)
		{
			case 'admin':
				$log_type = LOG_ADMIN;
				$sql_additional = '';
			break;

			case 'mod':
				$log_type = LOG_MOD;
				$sql_additional = '';

				if ($topic_id)
				{
					$sql_additional = 'AND l.topic_id = ' . (int) $topic_id;
				}
				else if (is_array($forum_id))
				{
					$sql_additional = 'AND ' . $this->db->sql_in_set('l.forum_id', array_map('intval', $forum_id));
				}
				else if ($forum_id)
				{
					$sql_additional = 'AND l.forum_id = ' . (int) $forum_id;
				}
			break;

			case 'user':
				$log_type = LOG_USERS;
				$sql_additional = 'AND l.reportee_id = ' . (int) $user_id;
			break;

			case 'users':
				$log_type = LOG_USERS;
				$sql_additional = '';
			break;

			case 'critical':
				$log_type = LOG_CRITICAL;
				$sql_additional = '';
			break;

			default:
				$log_type = null;
				$sql_additional = '';

				if ($this->dispatcher != null)
				{
					$vars = array('mode', 'count_logs', 'limit', 'offset', 'forum_id', 'topic_id', 'user_id', 'log_time', 'sort_by', 'keywords', 'profile_url', 'log_type', 'sql_additional');
					extract($this->dispatcher->trigger_event('core.get_logs_switch_mode', $vars));
				}

				if (!isset($log_type))
				{
					$this->logs_offset = 0;
					return array();
				}
		}

		if ($this->dispatcher != null)
		{
			$vars = array('mode', 'count_logs', 'limit', 'offset', 'forum_id', 'topic_id', 'user_id', 'log_time', 'sort_by', 'keywords', 'profile_url', 'log_type', 'sql_additional');
			extract($this->dispatcher->trigger_event('core.get_logs_after_get_type', $vars));
		}

		$sql_keywords = '';
		if (!empty($keywords))
		{
			// Get the SQL condition for our keywords
			$sql_keywords = self::generate_sql_keyword($keywords);
		}

		if ($count_logs)
		{
			$sql = 'SELECT COUNT(l.log_id) AS total_entries
				FROM ' . LOG_TABLE . ' l, ' . USERS_TABLE . " u
				WHERE l.log_type = $log_type
					AND l.user_id = u.user_id
					AND l.log_time >= $log_time
					$sql_keywords
					$sql_additional";
			$result = $this->db->sql_query($sql);
			$this->logs_total = (int) $this->db->sql_fetchfield('total_entries');
			$this->db->sql_freeresult($result);

			if ($this->logs_total == 0)
			{
				// Save the queries, because there are no logs to display
				$this->logs_offset = 0;
				return array();
			}

			// Return the user to the last page that is valid
			while ($this->logs_offset >= $this->logs_total)
			{
				$this->logs_offset = max(0, $this->logs_offset - $limit);
			}
		}

		$sql = 'SELECT l.*, u.username, u.username_clean, u.user_colour
			FROM ' . LOG_TABLE . ' l, ' . USERS_TABLE . " u
			WHERE l.log_type = $log_type
				AND u.user_id = l.user_id
				" . (($log_time) ? "AND l.log_time >= $log_time" : '') . "
				$sql_keywords
				$sql_additional
			ORDER BY $sort_by";
		$result = $this->db->sql_query_limit($sql, $limit, $this->logs_offset);

		$i = 0;
		$log = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$row['forum_id'] = (int) $row['forum_id'];
			if ($row['topic_id'])
			{
				$topic_id_list[] = (int) $row['topic_id'];
			}

			if ($row['reportee_id'])
			{
				$reportee_id_list[] = (int) $row['reportee_id'];
			}

			$log_entry_data = array(
				'id'				=> (int) $row['log_id'],

				'reportee_id'			=> (int) $row['reportee_id'],
				'reportee_username'		=> '',
				'reportee_username_full'=> '',

				'user_id'			=> (int) $row['user_id'],
				'username'			=> $row['username'],
				'username_full'		=> get_username_string('full', $row['user_id'], $row['username'], $row['user_colour'], false, $profile_url),

				'ip'				=> $row['log_ip'],
				'time'				=> (int) $row['log_time'],
				'forum_id'			=> (int) $row['forum_id'],
				'topic_id'			=> (int) $row['topic_id'],

				'viewforum'			=> ($row['forum_id'] && $auth->acl_get('f_read', $row['forum_id'])) ? append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $row['forum_id']) : false,
				'action'			=> (isset($user->lang[$row['log_operation']])) ? $user->lang[$row['log_operation']] : '{' . ucfirst(str_replace('_', ' ', $row['log_operation'])) . '}',
			);

			if ($this->dispatcher != null)
			{
				$vars = array('log_entry_data', 'row');
				extract($this->dispatcher->trigger_event('core.get_logs_entry_data', $vars));
			}

			$log[$i] = $log_entry_data;

			if (!empty($row['log_data']))
			{
				$log_data_ary = @unserialize($row['log_data']);
				$log_data_ary = ($log_data_ary !== false) ? $log_data_ary : array();

				if (isset($user->lang[$row['log_operation']]))
				{
					// Check if there are more occurrences of % than arguments, if there are we fill out the arguments array
					// It doesn't matter if we add more arguments than placeholders
					if ((substr_count($log[$i]['action'], '%') - sizeof($log_data_ary)) > 0)
					{
						$log_data_ary = array_merge($log_data_ary, array_fill(0, substr_count($log[$i]['action'], '%') - sizeof($log_data_ary), ''));
					}

					$log[$i]['action'] = vsprintf($log[$i]['action'], $log_data_ary);

					// If within the admin panel we do not censor text out
					if (defined('IN_ADMIN'))
					{
						$log[$i]['action'] = bbcode_nl2br($log[$i]['action']);
					}
					else
					{
						$log[$i]['action'] = bbcode_nl2br(censor_text($log[$i]['action']));
					}
				}
				else if (!empty($log_data_ary))
				{
					$log[$i]['action'] .= '<br />' . implode('', $log_data_ary);
				}

				/* Apply make_clickable... has to be seen if it is for good. :/
				// Seems to be not for the moment, reconsider later...
				$log[$i]['action'] = make_clickable($log[$i]['action']);
				*/
			}

			$i++;
		}
		$this->db->sql_freeresult($result);

		if ($this->dispatcher != null)
		{
			$vars = array('log', 'topic_id_list', 'reportee_id_list');
			extract($this->dispatcher->trigger_event('core.get_logs_additional_data', $vars));
		}

		if (sizeof($topic_id_list))
		{
			$topic_auth = self::get_topic_auth($topic_id_list);

			foreach ($log as $key => $row)
			{
				$log[$key]['viewtopic'] = (isset($topic_auth['f_read'][$row['topic_id']])) ? append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $topic_auth['f_read'][$row['topic_id']] . '&amp;t=' . $row['topic_id']) : false;
				$log[$key]['viewlogs'] = (isset($topic_auth['m_'][$row['topic_id']])) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=logs&amp;mode=topic_logs&amp;t=' . $row['topic_id'], true, $user->session_id) : false;
			}
		}

		if (sizeof($reportee_id_list))
		{
			$reportee_data_list = self::get_reportee_data($reportee_id_list);

			foreach ($log as $key => $row)
			{
				if (!isset($reportee_data_list[$row['reportee_id']]))
				{
					continue;
				}

				$log[$key]['reportee_username'] = $reportee_data_list[$row['reportee_id']]['username'];
				$log[$key]['reportee_username_full'] = get_username_string('full', $row['reportee_id'], $reportee_data_list[$row['reportee_id']]['username'], $reportee_data_list[$row['reportee_id']]['user_colour'], false, $profile_url);
			}
		}

		return $log;
	}

	/**
	* Get total log count
	*
	* @return	int			Returns the number of matching logs from the last call to get_logs()
	*/
	public function get_log_count()
	{
		return ($this->logs_total) ? $this->logs_total : 0;
	}

	/**
	* Get offset of the last valid log page
	*
	* @return	int			Returns the offset of the last valid page from the last call to get_logs()
	*/
	public function get_valid_offset()
	{
		return ($this->logs_offset) ? $this->logs_offset : 0;
	}

	/**
	* Generates a sql condition out of the specified keywords
	*
	* {@inheritDoc}
	*/
	static public function generate_sql_keyword($keywords)
	{
		global $db, $user;

		// Use no preg_quote for $keywords because this would lead to sole backslashes being added
		// We also use an OR connection here for spaces and the | string. Currently, regex is not supported for searching (but may come later).
		$keywords = preg_split('#[\s|]+#u', utf8_strtolower($keywords), 0, PREG_SPLIT_NO_EMPTY);
		$sql_keywords = '';

		if (!empty($keywords))
		{
			$keywords_pattern = array();

			// Build pattern and keywords...
			for ($i = 0, $num_keywords = sizeof($keywords); $i < $num_keywords; $i++)
			{
				$keywords_pattern[] = preg_quote($keywords[$i], '#');
				$keywords[$i] = $db->sql_like_expression($db->any_char . $keywords[$i] . $db->any_char);
			}

			$keywords_pattern = '#' . implode('|', $keywords_pattern) . '#ui';

			$operations = array();
			foreach ($user->lang as $key => $value)
			{
				if (substr($key, 0, 4) == 'LOG_' && preg_match($keywords_pattern, $value))
				{
					$operations[] = $key;
				}
			}

			$sql_keywords = 'AND (';
			if (!empty($operations))
			{
				$sql_keywords .= $db->sql_in_set('l.log_operation', $operations) . ' OR ';
			}
			$sql_keywords .= 'LOWER(l.log_data) ' . implode(' OR LOWER(l.log_data) ', $keywords) . ')';
		}

		return $sql_keywords;
	}

	/**
	* Determinate whether the user is allowed to read and/or moderate the forum of the topic
	*
	* {@inheritDoc}
	*/
	static public function get_topic_auth($topic_ids)
	{
		global $auth, $db;

		$forum_auth = array('f_read' => array(), 'm_' => array());
		$topic_ids = array_unique($topic_ids);

		$sql = 'SELECT topic_id, forum_id
			FROM ' . TOPICS_TABLE . '
			WHERE ' . $db->sql_in_set('topic_id', array_map('intval', $topic_ids));
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			$row['topic_id'] = (int) $row['topic_id'];
			$row['forum_id'] = (int) $row['forum_id'];

			if ($auth->acl_get('f_read', $row['forum_id']))
			{
				$forum_auth['f_read'][$row['topic_id']] = $row['forum_id'];
			}

			if ($auth->acl_gets('a_', 'm_', $row['forum_id']))
			{
				$forum_auth['m_'][$row['topic_id']] = $row['forum_id'];
			}
		}
		$db->sql_freeresult($result);

		return $forum_auth;
	}

	/**
	* Get the data for all reportee form the database
	*
	* {@inheritDoc}
	*/
	static public function get_reportee_data($reportee_ids)
	{
		global $db;

		$reportee_ids = array_unique($reportee_ids);
		$reportee_data_list = array();

		$sql = 'SELECT user_id, username, user_colour
			FROM ' . USERS_TABLE . '
			WHERE ' . $db->sql_in_set('user_id', $reportee_ids);
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			$reportee_data_list[$row['user_id']] = $row;
		}
		$db->sql_freeresult($result);

		return $reportee_data_list;
	}
}
