<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');

class Auto_forum_post_ext
{
	public $settings = array();

	public $name = 'Auto Forum Post';
	public $version = '1.0.1';
	public $description = 'Automatically post to forum when entry is submitted';
	public $settings_exist = 'y';
	public $docs_url = 'https://github.com/intoeetive/auto_forum_post';

	public function __construct($settings = '')
	{
		$this->EE =& get_instance();
		$this->settings = $settings;
		
		$this->EE->lang->loadfile('auto_forum_post');  
	}


	/* Activate Extension */
	function activate_extension()
    {
        
        $hooks = array(
    		array(
    			'hook'		=> 'entry_submission_end',
    			'method'	=> 'entry_submission_end',
    			'priority'	=> 10
    		),
    		array(
    			'hook'		=> 'forum_submit_post_end',
    			'method'	=> 'forum_submit_post_end',
    			'priority'	=> 10
    		),
    		array(
    			'hook'		=> 'insert_comment_end',
    			'method'	=> 'insert_comment_end',
    			'priority'	=> 10
    		)
    	);
    	
        foreach ($hooks AS $hook)
    	{
    		$data = array(
        		'class'		=> __CLASS__,
        		'method'	=> $hook['method'],
        		'hook'		=> $hook['hook'],
        		'settings'	=> '',
        		'priority'	=> $hook['priority'],
        		'version'	=> $this->version,
        		'enabled'	=> 'y'
        	);
            $this->EE->db->insert('extensions', $data);
    	}	

    }
    
    /**
     * Update Extension
     */
    function update_extension($current = '')
    {
    	if ($current == '' OR $current == $this->version)
    	{
    		return FALSE;
    	}
    }
    
    
    /**
     * Disable Extension
     */
    function disable_extension()
    {
    	$this->EE->db->where('class', __CLASS__);
    	$this->EE->db->delete('extensions');
    }
	
	
	
	function settings_form($current)
    {
    	$this->EE->load->helper('form');
    	$this->EE->load->library('table');
        
        $this->EE->db->select('hook, method, enabled');
        $this->EE->db->from('extensions');
        $this->EE->db->where('class', __CLASS__);
        $this->EE->db->where('enabled', 'y');
        $this->EE->db->where("(hook='forum_submit_post_end' OR hook='insert_comment_end')");
        //echo $this->EE->db->_compile_select();
        $ext_q = $this->EE->db->get();

        if ($ext_q->num_rows()>0)
        {
        	$sync_comments = 'y';
        }
        else
        {
        	$sync_comments = 'n';
        }

        $vars = array();

    	$yes_no_options = array(
    		'y' 	=> lang('yes'), 
    		'n'	=> lang('no')
    	);
    	
    	$custom_fields = array();
        $this->EE->db->select('field_id, field_label');
        $this->EE->db->order_by('field_order', 'asc');
        $q = $this->EE->db->get('exp_channel_fields');
        foreach ($q->result() as $obj)
        {
            $custom_fields['field_id_'.$obj->field_id] = $obj->field_label;
        }
        
        $channels = array();
        $this->EE->db->select('channel_id, channel_title');
        $this->EE->db->from('channels');
        $query = $this->EE->db->get();
        foreach ($query->result() as $obj)
        {
           $channels[$obj->channel_id] = $obj->channel_title;
        }
        
        $forums = array();
        $forums[''] = '';
        $this->EE->db->select('forum_id, forum_name');
        $this->EE->db->from('exp_forums');
        $this->EE->db->where('forum_is_cat', 'n');
        $query = $this->EE->db->get();
        foreach ($query->result() as $obj)
        {
           $forums[$obj->forum_id] = $obj->forum_name;
        }
            
        $vars['settings'][lang('post_text')] = form_dropdown(
    					'post_text',
    					$custom_fields, 
    					$current['post_text']);
    					
		$vars['settings'][lang('sync_comments')] = form_dropdown(
					'sync_comments',
					$yes_no_options, 
					$sync_comments);
					
		foreach ($channels as $channel_id=>$channel_title)
		{
			if (!isset($current['channel_'.$channel_id])) $current['channel_'.$channel_id] = '';
			$vars['settings'][lang('channel_forum').NBS.$channel_title] = form_dropdown(
    					'channel_'.$channel_id,
    					$forums, 
    					$current['channel_'.$channel_id]);
		}

    	return $this->EE->load->view('settings', $vars, TRUE);			
    }
    
    
    
    
    function save_settings()
    {
    	if (empty($_POST))
    	{
    		show_error($this->EE->lang->line('unauthorized_access'));
    	}
        
        if (isset($_POST['sync_comments']) && $_POST['sync_comments']!='')
        {
            $data = array('enabled' => $this->EE->input->post('sync_comments'));
            
            $this->EE->db->where('class', __CLASS__);
        	$this->EE->db->where('hook', 'forum_submit_post_end');
            $this->EE->db->update('extensions', $data);
            
            $this->EE->db->where('class', __CLASS__);
        	$this->EE->db->where('hook', 'insert_comment_end');
            $this->EE->db->update('extensions', $data);
        }
		
		unset($_POST['submit']);
		unset($_POST['sync_comments']);

        $this->EE->db->where('class', __CLASS__);
    	$this->EE->db->update('extensions', array('settings' => serialize($_POST)));
    	
    	$this->EE->session->set_flashdata(
    		'message_success',
    	 	$this->EE->lang->line('preferences_updated')
    	);
    }
    
    
	
	/* Create forum thread while article posted */
	public function entry_submission_end($entry_id, $meta, $edata)
	{
		if ($this->EE->config->item('forum_is_installed') != 'y') return false;
		
		$edata = array_merge($edata, $meta);
		
		//if ($edata['status']=='')
        //{
        	//better workflow compatibility
			foreach($_POST as $k => $v) 
			{
				if (preg_match('/^epBwfEntry/',$k))
				{
					$edata['status'] = array_pop(explode('|',$v));
					break;
				}
			}
        //}
        
        //var_dump($edata['status']);
        //var_dump($this->settings['channel_'.$edata['channel_id']]);
        //exit();
        
        if ($edata['status']!='open') return false;
        
        if (!isset($this->settings['channel_'.$edata['channel_id']]) || $this->settings['channel_'.$edata['channel_id']]=='') return false;

        $q = $this->EE->db->select('channel_url, comment_url')
					->from('channels')
					->where('channel_id', $edata['channel_id'])
					->get();
		$channel_data = $q->row_array();
		$basepath = ($channel_data['comment_url']!='') ? $channel_data['comment_url'] : $channel_data['channel_url'];

		/* Fetch the first paragraph of article body */
		
		$body = $edata[$this->settings['post_text']];
		
		//$start 		= strpos($body, '<p>');
		//$end 		= strpos($body, '</p>', $start);
		//$firstP 	= substr($body, $start, $end - $start + 4);

		$new_body 	= $body.'<p>&nbsp;</p><p><a href="'.$this->EE->functions->remove_double_slashes($basepath.'/'.$edata['url_title']).'">'.lang('read_more').'</a></p>';

		$data = array(
						'title'			=> $edata['title'],
						'body'			=> $new_body,
						'sticky'		=> 'n',
						'status'		=> 'o',
						'announcement'	=> 'n',
						'poll'			=> 'n',
						'parse_smileys'	=> 'y'
					);

		/* check if that article article already posted as forum topic */
		$q = $this->EE->db->select('forum_topic_id')->from('channel_titles')->where('entry_id', $entry_id)->get();
        if ($q->row('forum_topic_id')=='')
		{
			$board_q = $this->EE->db->select('board_id')->from('forums')->where('forum_id', $this->settings['channel_'.$edata['channel_id']])->get();
			
			$data['author_id']				= $edata['author_id'];
			$data['ip_address']				= $this->EE->input->ip_address();
			$data['forum_id'] 				= $this->settings['channel_'.$edata['channel_id']];
			$data['last_post_date'] 		= $this->EE->localize->now;
			$data['last_post_author_id']	= $edata['author_id'];
			$data['thread_total']			= 1;
			$data['topic_date']				= $this->EE->localize->now;
			$data['board_id']				= $board_q->row('board_id');

			$this->EE->db->insert('forum_topics', $data);
			$forum_topic_id = $this->EE->db->insert_id();
			
			$fdata = array('forum_topic_id' => $forum_topic_id);
			$this->EE->db->where('entry_id', $entry_id);
			$this->EE->db->update('channel_titles', $fdata);

			// Update the forum stats
			$this->_update_post_stats($this->settings['channel_'.$edata['channel_id']]);
			$this->_update_global_stats();
		}
		else
		{
			$forum_topic_id = $q->row('forum_topic_id');

			$data['topic_edit_author']	= $this->EE->session->userdata['member_id'];
			$data['topic_edit_date']	= $this->EE->localize->now;

			$this->EE->db->where('topic_id', $forum_topic_id);
			$this->EE->db->update('forum_topics', $data);
		}

		// Update member post total
		$this->EE->db->where('member_id', $data['author_id']);
		$this->EE->db->update('members', 
								array('last_forum_post_date' => $this->EE->localize->now)
							);
	}
	
	
	

	public function insert_comment_end($cdata, $comment_moderate, $comment_id)
	{
		if($cdata['author_id'] == 0) return;

		$query = $this->EE->db->query("SELECT forum_topic_id, channel_id FROM exp_channel_titles WHERE entry_id='".$cdata['entry_id']."'");
		if ($query->num_rows() == 0 || $query->row('forum_topic_id') == '')
		{
			return ;
		}
		else
		{
			$forum_topic_id = $query->row('forum_topic_id');
			$comment = $cdata['comment'];
			$body = $this->_convert_forum_tags($comment);

			/* The forum $forum_id will be based on "Article Type" of article and should be mapped with */
			$query1 = $this->EE->db->select('forum_id, board_id')->from('forum_topics')->where('topic_id', $forum_topic_id)->get();

			$data = array(
							'topic_id'		=> $this->EE->db->escape_str($forum_topic_id),
							'forum_id'		=> $query1->row('forum_id'),
							'body'			=> $this->EE->security->xss_clean($body),
							'parse_smileys'	=> 'y'
						 );

			$data['author_id']	= $cdata['author_id'];
			$data['ip_address']	= $this->EE->input->ip_address();
			$data['post_date']	= $this->EE->localize->now;
			$data['board_id']	= $query1->row('board_id');

			$this->EE->db->query($this->EE->db->insert_string('exp_forum_posts', $data));	

			$post_id = $this->EE->db->insert_id();

			// Update the topic stats (count, last post info)
			$this->_update_topic_stats($forum_topic_id);

			// Update the forum stats
			$this->_update_post_stats($query1->row('forum_id'));
			$this->_update_global_stats();

			// Update member post total
			$this->EE->db->where('member_id', $cdata['author_id']);
			$this->EE->db->update('members', 
									array('last_forum_post_date' => $this->EE->localize->now)
								);
		}
		
		/*
		Array
		(
			[channel_id] => 1
			[entry_id] => 5
			[author_id] => 1
			[name] => EEDEMO5.2
			[email] => bhashkar@w3care.com
			[url] => 
			[location] => 
			[comment] => I made my Test debut atour one-dayers
			[ip_address] => 127.0.0.1
			[status] => o
			[site_id] => 1
		)
		*/
	}
	
	/* Create forum thread while article posted */
	public function forum_submit_post_end($obj, $data)
	{

		$query = $this->EE->db->query("SELECT entry_id, channel_id FROM exp_channel_titles WHERE forum_topic_id='".$data['topic_id']."'");
		if ($query->num_rows() == 0)
		{
			return ;
		}
		else
		{
			if (!isset($this->settings['channel_'.$query->row('channel_id')]) || $this->settings['channel_'.$query->row('channel_id')]=='') return false;
			
			
			$query1 = $this->EE->db->query("SELECT screen_name, email FROM exp_members WHERE member_id ='".$data['author_id']."'");
			$cmtr_name = $query1->row('screen_name');
			$cmtr_email = $query1->row('email');

			$data = array(
				'channel_id'	=> $query->row('channel_id'),
				'entry_id'		=> $query->row('entry_id'),
				'author_id'		=> $data['author_id'],
				'name'			=> $cmtr_name,
				'email'			=> $cmtr_email,
				'url'			=> '',
				'location'		=> '',
				'comment'		=> $this->EE->security->xss_clean($data['body']),
				'comment_date'	=> $this->EE->localize->now,
				'ip_address'	=> $this->EE->input->ip_address(),
				'status'		=> 'o',
				'site_id'		=> $this->EE->config->item('site_id')
			);

			$sql = $this->EE->db->insert_string('exp_comments', $data);
			$this->EE->db->query($sql);
			$comment_id = $this->EE->db->insert_id();
		}

		/*
		$data = 
		Array
		(
			[topic_id] => 4
			[forum_id] => 4
			[body] => "People who want love marriages can move out of our village,"said  Mohman Khan, a member of the panchayat in the Asaara village in Baghpat.  

			Khaps are dominant institutions that are hard to defy. Villagers have no choice but to fall in line.
			[parse_smileys] => y
			[author_id] => 1
			[ip_address] => 127.0.0.1
			[post_date] => 1342156572
			[board_id] => 1
			[post_id] => 4
		)
		*/
	}

	
	private function _update_post_stats($forum_id)
	{
		$cache_off = FALSE;
		
		if ($this->EE->db->cache_on === TRUE)
		{
			$this->EE->db->cache_off();
			$cache_off = TRUE;
		}
		
		$data = array(
						'forum_last_post_id' 		=> 0,
						'forum_last_post_type'		=> 'p',
						'forum_last_post_title'		=> '',
						'forum_last_post_date'		=> 0,
						'forum_last_post_author_id'	=> 0,
						'forum_last_post_author'	=> ''		
					);
		
		$this->EE->db->select('COUNT(*) as count');
		$query = $this->EE->db->get_where('forum_topics', array('forum_id' => $forum_id));	
		$data['forum_total_topics'] = $query->row('count') ;

		$this->EE->db->select('COUNT(*) as count');
		$query = $this->EE->db->get_where('forum_posts', array('forum_id' => $forum_id));
		$data['forum_total_posts'] = $query->row('count') ;
		
		$this->EE->db->select('topic_id, title, topic_date, last_post_date, 
								last_post_author_id, screen_name, announcement');
		$this->EE->db->from(array('forum_topics', 'members'));
		$this->EE->db->where('member_id', 'last_post_author_id', FALSE);
		$this->EE->db->where('forum_id', $forum_id);
		$this->EE->db->order_by('last_post_date', 'DESC');
		$this->EE->db->limit(1);
		$query = $this->EE->db->get();
		
		if ($query->num_rows() > 0)
		{
			$data['forum_last_post_id'] 		= $query->row('topic_id');
			$data['forum_last_post_type'] 		= ($query->row('announcement')  == 'n') ? 'p' : 'a';
			$data['forum_last_post_title'] 		= $query->row('title');
			$data['forum_last_post_date'] 		= $query->row('topic_date');
			$data['forum_last_post_author_id']	= $query->row('last_post_author_id');
			$data['forum_last_post_author']		= $query->row('screen_name');
		}
		
		$this->EE->db->select('post_date, author_id, screen_name');
		$this->EE->db->from(array('forum_posts', 'members'));
		$this->EE->db->where('member_id', 'author_id', FALSE);
		$this->EE->db->where('forum_id', $forum_id);
		$this->EE->db->order_by('post_date', 'DESC');
		$this->EE->db->limit(1);
		$query = $this->EE->db->get();

		if ($query->num_rows() > 0)
		{
			if ($query->row('post_date')  > $data['forum_last_post_date'])
			{
				$data['forum_last_post_date'] 		= $query->row('post_date') ;
				$data['forum_last_post_author_id']	= $query->row('author_id') ;
				$data['forum_last_post_author']		= $query->row('screen_name') ;
			}
		}

		$this->EE->db->query($this->EE->db->update_string('exp_forums', $data, "forum_id='{$forum_id}'"));
		unset($data);
		
		// Update member stats
		$this->EE->db->select('COUNT(*) as count');
		$query = $this->EE->db->get_where('forum_topics', 
											array('author_id' => $this->EE->session->userdata('member_id')));
		$total_topics = $query->row('count') ;
		
		$this->EE->db->select('COUNT(*) as count');
		$query = $this->EE->db->get_where('forum_posts', 
											array('author_id' => $this->EE->session->userdata('member_id')));
		$total_posts = $query->row('count') ;
		
		$d = array(
					'total_forum_topics'	=> $total_topics,
					'total_forum_posts'		=> $total_posts
				);
		$this->EE->db->where('member_id', $this->EE->session->userdata('member_id'));
		$this->EE->db->update('members', $d);

		if ($cache_off)
		{
			$this->EE->db->cache_on();
		}
	}
	
	
	
	private function _update_global_stats()		
	{
		$cache_off = FALSE;
		
		if ($this->EE->db->cache_on === TRUE)
		{
			$this->EE->db->cache_off();
			$cache_off = TRUE;
		}

		$total_topics = $this->EE->db->count_all('forum_topics');
		$total_posts  = $this->EE->db->count_all('forum_posts');

		$this->EE->db->update('stats', array(
										'total_forum_topics'	=> $total_topics,
										'total_forum_posts'		=> $total_posts));

		if ($cache_off)
		{
			$this->EE->db->cache_on();
		}
	}



	private function _update_topic_stats($topic_id)
	{
		$cache_off = FALSE;
		
		if ($this->EE->db->cache_on === TRUE)
		{
			$this->EE->db->cache_off();
			$cache_off = TRUE;
		}

		// Update the thread count and last post date
		$this->EE->db->select('COUNT(*) as count, MAX(post_date) as last_post');
		$query = $this->EE->db->get_where('forum_posts', array('topic_id' => $topic_id));

		$this->thread_post_total = $query->row('count') ;
		$total = ($query->row('count')  + 1);
		
		if ($query->row('count')  > 0)
		{
			$d = array(
					'last_post_date'	=> $query->row('last_post'),
					'thread_total'		=> $total
				);
			
			$this->EE->db->where('topic_id', $topic_id);
			$this->EE->db->update('forum_topics', $d);
		}
		else
		{
			$this->EE->db->set('last_post_date', 'topic_date', FALSE);
			$this->EE->db->set('thread_total', $total);
			$this->EE->db->where('topic_id', $topic_id);
			$this->EE->db->update('forum_topics');
		}

		// Update the resulting last post author and last post id
		if ($total > 1)
		{
			$this->EE->db->select('post_id, author_id');
			$this->EE->db->where('topic_id', $topic_id);
			$this->EE->db->order_by('post_date', 'DESC');
			$this->EE->db->limit(1);
			$query = $this->EE->db->get('forum_posts');

			$d = array(
					'last_post_author_id'	=> $query->row('author_id'),
					'last_post_id'			=> $query->row('post_id')
				);

			$this->EE->db->where('topic_id', $topic_id);
			$this->EE->db->update('forum_topics', $d);
		}
		else
		{
			$this->EE->db->set('last_post_author_id', 'author_id', FALSE);
			$this->EE->db->set('last_post_id', 0);
			$this->EE->db->where('topic_id', $topic_id);
			$this->EE->db->update('forum_topics');			
		}

		if ($cache_off)
		{
			$this->EE->db->cache_on();
		}
	}
	
	private function _convert_forum_tags($str)
	{	
		$str = str_replace('{include:', '&#123;include:', $str);
		$str = str_replace('{path:', '&#123;path:', $str);
		$str = str_replace('{lang:', '&#123;lang:', $str);
		
		return $str;
	}	
}