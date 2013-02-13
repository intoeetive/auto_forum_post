<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

class Auto_forum_post_ext
{
	public $settings = array();

	public $name = 'Auto Forum Post';
	public $version = '1.1';
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

		unset($_POST['submit']);

        $this->EE->db->where('class', __CLASS__);
    	$this->EE->db->update('extensions', array('settings' => serialize($_POST)));
    	
    	$this->EE->session->set_flashdata(
    		'message_success',
    	 	$this->EE->lang->line('preferences_updated')
    	);
    }
    
    
	
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
		

		$post_text 	= $edata[$this->settings['post_text']].BR.BR.'<a href="'.$this->EE->functions->remove_double_slashes($basepath.'/'.$edata['url_title']).'">'.lang('read_more').'</a>';

		$data = array(
						'title'			=> $edata['title'],
						'body'			=> $post_text,
						'sticky'		=> 'n',
						'status'		=> 'o',
						'announcement'	=> 'n',
						'poll'			=> 'n',
						'parse_smileys'	=> 'y'
					);

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

			
			require_once PATH_MOD.'forum/mod.forum_core.php';

			$this->EE->FRM_CORE = new Forum_Core();
			$this->EE->FRM_CORE->_update_post_stats($this->settings['channel_'.$edata['channel_id']]);
			$this->EE->FRM_CORE->_update_global_stats();
		}
		else
		{
			$forum_topic_id = $q->row('forum_topic_id');

			$data['topic_edit_author']	= $this->EE->session->userdata['member_id'];
			$data['topic_edit_date']	= $this->EE->localize->now;

			$this->EE->db->where('topic_id', $forum_topic_id);
			$this->EE->db->update('forum_topics', $data);
		}

		$this->EE->db->where('member_id', $data['author_id']);
		$this->EE->db->update('members', 
								array('last_forum_post_date' => $this->EE->localize->now)
							);
	}

}