<?php
namespace MasCamp\GoogleDocsSync;
if (!defined('ABSPATH')) exit;

final class Module {
    private static $instance;
    private $option = 'mascamp_docsync_settings';
    private $token_option = 'mascamp_docsync_tokens';
    public static function instance() { return self::$instance ?: (self::$instance = new self()); }
    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_mascamp_gdocs_connect', [$this, 'connect']);
        add_action('admin_post_mascamp_gdocs_callback', [$this, 'oauth_callback']);
        add_action('admin_post_mascamp_gdocs_disconnect', [$this, 'disconnect']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post_post', [$this, 'queue_on_save'], 20, 3);
        add_action('save_post_page', [$this, 'queue_on_save'], 20, 3);
        add_action('transition_post_status', [$this, 'queue_on_publish'], 20, 3);
        add_action('mascamp_docsync_post', [$this, 'sync_post'], 10, 1);
        add_action('admin_post_mascamp_docsync_now', [$this, 'sync_now']);
        add_action('admin_notices', [$this, 'notices']);
    }
    private function settings() { return wp_parse_args(get_option($this->option, []), [
        'client_id'=>'','client_secret'=>'','folder_id'=>'','auto_sync'=>'1'
    ]); }
    private function tokens() { return get_option($this->token_option, []); }
    /** Accepts a bare folder ID or a full Google Drive folder URL and returns just the ID. */
    private static function extract_folder_id($value) {
        $value=trim(sanitize_text_field($value));
        if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $value, $m)) return $m[1];
        if (preg_match('~[?&]id=([A-Za-z0-9_-]+)~', $value, $m)) return $m[1];
        return preg_replace('~[^A-Za-z0-9_-]~', '', $value);
    }
    public function admin_menu() {
        add_submenu_page('options-general.php', 'MasCamp DocSync', 'MasCamp DocSync', 'manage_options', 'mascamp-docsync', [$this,'settings_page']);
    }
    public function register_settings() {
        register_setting('mascamp_docsync', $this->option, [
            'type'=>'array', 'sanitize_callback'=>function($v) {
                $old=$this->settings();
                return [
                    'client_id'=>sanitize_text_field($v['client_id'] ?? ''),
                    // Keep secrets only if a value was submitted. Prefer server environment constants for production.
                    'client_secret'=>sanitize_text_field($v['client_secret'] ?? $old['client_secret']),
                    'folder_id'=>self::extract_folder_id($v['folder_id'] ?? ''),
                    'auto_sync'=>!empty($v['auto_sync']) ? '1':'0'
                ];
            }
        ]);
    }
    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s=$this->settings(); $t=$this->tokens();
        $connected=!empty($t['refresh_token']) || !empty($t['access_token']);
        echo '<div class="wrap"><h1>MasCamp DocSync</h1>';
        echo '<p>Sync published WordPress posts and pages to one Google Drive folder. Configure OAuth in Google Cloud Console first.</p>';
        echo '<p><strong>Authorized redirect URI</strong> (paste into your Google OAuth client):<br><input type="text" class="large-text code" readonly onclick="this.select()" value="'.esc_attr($this->redirect_uri()).'"></p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mascamp_docsync');
        echo '<table class="form-table"><tr><th>OAuth Client ID</th><td><input class="regular-text" name="'.esc_attr($this->option).'[client_id]" value="'.esc_attr($s['client_id']).'"></td></tr>';
        echo '<tr><th>OAuth Client Secret</th><td><input type="password" autocomplete="new-password" class="regular-text" name="'.esc_attr($this->option).'[client_secret]" value="" placeholder="'.($s['client_secret']?'Saved — leave blank to keep':'Paste client secret').'"><p class="description">Stored in the WordPress options table. For hardened deployments, define MASCAMP_GDOCS_CLIENT_SECRET in wp-config.php instead.</p></td></tr>';
        echo '<tr><th>Google Drive folder ID</th><td><input class="regular-text" name="'.esc_attr($this->option).'[folder_id]" value="'.esc_attr($s['folder_id']).'"><p class="description">Paste the folder ID or the full Google Drive folder URL; the ID is extracted on save. The OAuth account must have access.</p></td></tr>';
        echo '<tr><th>Automatic sync</th><td><label><input type="checkbox" name="'.esc_attr($this->option).'[auto_sync]" value="1" '.checked($s['auto_sync'],'1',false).'> Queue sync when a published post/page is updated</label></td></tr></table>';
        submit_button('Save Settings'); echo '</form>';
        echo '<hr><h2>Google authorization</h2>';
        if ($connected) {
            echo '<p><strong>Connected</strong> — token saved.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mascamp_gdocs_disconnect">'; wp_nonce_field('mascamp_gdocs_disconnect'); submit_button('Disconnect Google account','secondary'); echo '</form>';
        } else {
            echo '<p>Authorize the Google account that owns or can edit the destination folder.</p><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=mascamp_gdocs_connect'),'mascamp_gdocs_connect')).'">Connect Google account</a>';
        }
        echo '<hr><h2>How images work</h2><p>Google Docs can embed images only when Google can fetch the image URL. Public WordPress Media Library URLs are supported; protected or expiring image URLs may be skipped and recorded as a warning.</p>';
        echo '</div>';
    }
    private function redirect_uri() { return admin_url('admin-post.php?action=mascamp_gdocs_callback'); }
    public function connect() {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        check_admin_referer('mascamp_gdocs_connect');
        $s=$this->settings();
        if (!$s['client_id'] || !$this->client_secret()) wp_die('Add the Google OAuth Client ID and Client Secret in settings first.');
        $state=wp_generate_password(32,false,false);
        set_transient('mascamp_gdocs_oauth_state_'.get_current_user_id(), $state, 10*MINUTE_IN_SECONDS);
        $url=add_query_arg([
            'client_id'=>$s['client_id'],'redirect_uri'=>$this->redirect_uri(),'response_type'=>'code',
            'scope'=>'https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive',
            'access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'true','state'=>$state
        ],'https://accounts.google.com/o/oauth2/v2/auth');
        wp_redirect($url); exit;
    }
    public function oauth_callback() {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        $expected=get_transient('mascamp_gdocs_oauth_state_'.get_current_user_id());
        delete_transient('mascamp_gdocs_oauth_state_'.get_current_user_id());
        if (!$expected || !isset($_GET['state']) || !hash_equals($expected, sanitize_text_field(wp_unslash($_GET['state'])))) wp_die('OAuth state validation failed.',403);
        if (!empty($_GET['error'])) $this->finish('error', 'Google authorization was cancelled or denied.');
        $code=sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if (!$code) wp_die('Missing OAuth authorization code.');
        $s=$this->settings();
        $res=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>20,'body'=>[
            'code'=>$code,'client_id'=>$s['client_id'],'client_secret'=>$this->client_secret(),
            'redirect_uri'=>$this->redirect_uri(),'grant_type'=>'authorization_code'
        ]]);
        if (is_wp_error($res)) $this->finish('error',$res->get_error_message());
        $body=json_decode(wp_remote_retrieve_body($res),true);
        if (wp_remote_retrieve_response_code($res)>=300 || empty($body['access_token'])) $this->finish('error','Google token exchange failed. Check OAuth redirect URI and credentials.');
        $old=$this->tokens();
        $save=['access_token'=>$body['access_token'],'expires_at'=>time()+absint($body['expires_in'] ?? 3600)-60,
            'scope'=>sanitize_text_field($body['scope'] ?? '')];
        if (!empty($body['refresh_token'])) $save['refresh_token']=$body['refresh_token'];
        elseif (!empty($old['refresh_token'])) $save['refresh_token']=$old['refresh_token'];
        update_option($this->token_option,$save,false);
        $this->finish('success','Google account connected.');
    }
    public function disconnect() {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        check_admin_referer('mascamp_gdocs_disconnect');
        delete_option($this->token_option);
        $this->finish('success','Google account disconnected.');
    }
    private function finish($type,$message) {
        set_transient('mascamp_gdocs_notice_'.get_current_user_id(),[$type,$message],60);
        wp_safe_redirect(admin_url('options-general.php?page=mascamp-docsync')); exit;
    }
    private function client_secret() {
        if (defined('MASCAMP_GDOCS_CLIENT_SECRET') && MASCAMP_GDOCS_CLIENT_SECRET) return MASCAMP_GDOCS_CLIENT_SECRET;
        return $this->settings()['client_secret'];
    }
    private function access_token() {
        $t=$this->tokens();
        if (empty($t['access_token'])) return new \WP_Error('not_connected','Connect a Google account first.');
        if (!empty($t['expires_at']) && $t['expires_at']>time()+30) return $t['access_token'];
        if (empty($t['refresh_token'])) return new \WP_Error('refresh_missing','Reconnect Google account to renew authorization.');
        $s=$this->settings();
        $r=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>20,'body'=>[
            'client_id'=>$s['client_id'],'client_secret'=>$this->client_secret(),
            'refresh_token'=>$t['refresh_token'],'grant_type'=>'refresh_token'
        ]]);
        if (is_wp_error($r)) return $r;
        $b=json_decode(wp_remote_retrieve_body($r),true);
        if (wp_remote_retrieve_response_code($r)>=300 || empty($b['access_token'])) return new \WP_Error('refresh_failed','Google token refresh failed. Reconnect the account.');
        $t['access_token']=$b['access_token']; $t['expires_at']=time()+absint($b['expires_in'] ?? 3600)-60;
        update_option($this->token_option,$t,false);
        return $t['access_token'];
    }
    public function add_metabox() {
        foreach (['post','page'] as $type) add_meta_box('mascamp-docsync','Google Docs Sync',[$this,'metabox'],$type,'side','high');
    }
    public function metabox($post) {
        if (!current_user_can('edit_post',$post->ID)) return;
        $id=get_post_meta($post->ID,'_mascamp_gdocs_document_id',true);
        $status=get_post_meta($post->ID,'_mascamp_docsync_status',true);
        $synced=get_post_meta($post->ID,'_mascamp_gdocs_last_synced',true);
        $error=get_post_meta($post->ID,'_mascamp_docsync_error',true);
        wp_nonce_field('mascamp_gdocs_metabox','mascamp_gdocs_metabox_nonce');
        echo '<p>Status: <strong>'.esc_html($status ?: 'Not synced').'</strong></p>';
        if ($synced) echo '<p>Last synced: '.esc_html($synced).'</p>';
        if ($id) echo '<p><a target="_blank" rel="noopener" href="'.esc_url('https://docs.google.com/document/d/'.$id.'/edit').'">Open Google Doc</a></p>';
        if ($error) echo '<p style="color:#b32d2e">'.esc_html($error).'</p>';
        echo '<p><button type="submit" class="button button-primary" name="mascamp_docsync_now" value="1">Sync to Google Docs now</button></p>';
        echo '<p class="description">The document is linked to this WordPress post and updated on subsequent syncs.</p>';
    }
    public function sync_now() {
        $post_id=absint($_POST['post_ID'] ?? 0);
        if (!$post_id || !current_user_can('edit_post',$post_id)) wp_die('Forbidden',403);
        check_admin_referer('mascamp_gdocs_metabox','mascamp_gdocs_metabox_nonce');
        $result=$this->perform_sync($post_id);
        set_transient('mascamp_gdocs_notice_'.get_current_user_id(),[$result===true?'success':'error',$result===true?'Post synced to Google Docs.':(is_wp_error($result)?$result->get_error_message():'Sync failed.')],60);
        wp_safe_redirect(get_edit_post_link($post_id,'url')); exit;
    }
    public function queue_on_save($post_id,$post,$update) {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !($post instanceof \WP_Post)) return;
        if (!current_user_can('edit_post',$post_id) || $post->post_status!=='publish') return;
        $s=$this->settings();
        if ($s['auto_sync']==='1') $this->queue($post_id);
    }
    public function queue_on_publish($new,$old,$post) {
        if (!($post instanceof \WP_Post) || !in_array($post->post_type,['post','page'],true) || $new!=='publish' || $old==='publish') return;
        if (!current_user_can('edit_post',$post->ID)) return;
        if ($this->settings()['auto_sync']==='1') $this->queue($post->ID);
    }
    private function queue($post_id) {
        if (!wp_next_scheduled('mascamp_docsync_post',[$post_id])) wp_schedule_single_event(time()+15,'mascamp_docsync_post',[$post_id]);
        update_post_meta($post_id,'_mascamp_docsync_status','Queued');
        delete_post_meta($post_id,'_mascamp_docsync_error');
    }
    public function sync_post($post_id) { $this->perform_sync(absint($post_id)); }
    private function api($method,$url,$body=null) {
        $token=$this->access_token(); if (is_wp_error($token)) return $token;
        $args=['method'=>$method,'timeout'=>45,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json']];
        if ($body!==null) $args['body']=wp_json_encode($body);
        $r=wp_remote_request($url,$args); if (is_wp_error($r)) return $r;
        $code=wp_remote_retrieve_response_code($r); $data=json_decode(wp_remote_retrieve_body($r),true);
        if ($code<200 || $code>=300) return new \WP_Error('google_api_error',$data['error']['message'] ?? ('Google API returned HTTP '.$code));
        return $data ?: [];
    }
    private function perform_sync($post_id) {
        $post=get_post($post_id);
        if (!$post || !in_array($post->post_type,['post','page'],true)) return new \WP_Error('invalid_post','Only posts and pages can be synced.');
        if ($post->post_status!=='publish') return new \WP_Error('not_published','Publish the post or page before syncing.');
        if (!current_user_can('edit_post',$post_id) && !wp_doing_cron()) return new \WP_Error('forbidden','You cannot edit this post.');
        $s=$this->settings();
        if (!$s['folder_id']) return $this->mark_error($post_id,new \WP_Error('folder_missing','Set the Google Drive folder ID in settings.'));
        update_post_meta($post_id,'_mascamp_docsync_status','Syncing');
        delete_post_meta($post_id,'_mascamp_docsync_error');
        $doc_id=get_post_meta($post_id,'_mascamp_gdocs_document_id',true);
        if (!$doc_id) {
            $created=$this->api('POST','https://www.googleapis.com/drive/v3/files?fields=id,name,mimeType',[
                'name'=>wp_strip_all_tags(get_the_title($post_id)) ?: 'WordPress post '.$post_id,
                'mimeType'=>'application/vnd.google-apps.document','parents'=>[$s['folder_id']],
                'description'=>'Synced from WordPress post ID '.$post_id.' — '.get_permalink($post_id)
            ]);
            if (is_wp_error($created)) return $this->mark_error($post_id,$created);
            if (empty($created['id'])) return $this->mark_error($post_id,new \WP_Error('create_failed','Google Drive did not return a document ID.'));
            $doc_id=sanitize_text_field($created['id']);
            update_post_meta($post_id,'_mascamp_gdocs_document_id',$doc_id);
        }
        $doc=$this->api('GET','https://docs.googleapis.com/v1/documents/'.rawurlencode($doc_id));
        if (is_wp_error($doc)) return $this->mark_error($post_id,$doc);
        $end=isset($doc['body']['content']) ? max(1,(int)end($doc['body']['content'])['endIndex']-1) : 1;
        $requests=[];
        if ($end>1) $requests[]=['deleteContent'=>['range'=>['startIndex'=>1,'endIndex'=>$end]]];
        $title=wp_strip_all_tags(get_the_title($post_id));
        $html=$this->content_html($post_id);
        $converted=Converter::convert($title,$html,get_permalink($post_id),get_the_post_thumbnail_url($post_id,'full'));
        $text=$converted['text'];
        $requests[]=['insertText'=>['location'=>['index'=>1],'text'=>$text]];
        foreach ($converted['styles'] as $style) {
            $requests[]=['updateTextStyle'=>['range'=>['startIndex'=>$style['start'],'endIndex'=>$style['end']],'textStyle'=>$style['style'],'fields'=>implode(',',array_keys($style['style']))]];
        }
        foreach ($converted['paragraphs'] as $p) {
            $requests[]=['updateParagraphStyle'=>['range'=>['startIndex'=>$p['start'],'endIndex'=>$p['end']],'paragraphStyle'=>['namedStyleType'=>$p['type']],'fields'=>'namedStyleType']];
        }
        // Google Docs API requires image URLs to be publicly fetchable by Google.
        foreach ($converted['images'] as $img) {
            $requests[]=['insertInlineImage'=>['location'=>['index'=>$img['index']],'uri'=>$img['url'],'objectSize'=>['width'=>['magnitude'=>420,'unit'=>'PT']]]];
        }
        // Keep request payload bounded; very large posts should be split in a future enhancement.
        if (count($requests)>400) return $this->mark_error($post_id,new \WP_Error('too_complex','This post contains too many formatting/image operations for one sync. Split the content or simplify formatting.'));
        $updated=$this->api('POST','https://docs.googleapis.com/v1/documents/'.rawurlencode($doc_id).':batchUpdate',['requests'=>$requests]);
        if (is_wp_error($updated)) return $this->mark_error($post_id,$updated);
        update_post_meta($post_id,'_mascamp_docsync_status','Synced');
        update_post_meta($post_id,'_mascamp_gdocs_last_synced',current_time('mysql'));
        delete_post_meta($post_id,'_mascamp_docsync_error');
        update_post_meta($post_id,'_mascamp_gdocs_source_url',esc_url_raw(get_permalink($post_id)));
        return true;
    }
    private function content_html($post_id) {
        $post=get_post($post_id);
        $html=apply_filters('the_content',$post->post_content);
        return do_shortcode($html);
    }
    private function mark_error($post_id,$error) {
        $message=is_wp_error($error)?$error->get_error_message():'Unknown sync error';
        update_post_meta($post_id,'_mascamp_docsync_status','Error');
        update_post_meta($post_id,'_mascamp_docsync_error',sanitize_text_field($message));
        return is_wp_error($error)?$error:new \WP_Error('sync_error',$message);
    }
    public function notices() {
        $key='mascamp_gdocs_notice_'.get_current_user_id(); $n=get_transient($key);
        if (!$n) return; delete_transient($key);
        echo '<div class="notice notice-'.esc_attr($n[0]==='success'?'success':'error').' is-dismissible"><p>'.esc_html($n[1]).'</p></div>';
    }
}
