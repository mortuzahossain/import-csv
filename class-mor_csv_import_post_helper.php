<?php
class RSCSV_Import_Post_Helper
{
    const CFS_PREFIX = 'cfs_';
    const SCF_PREFIX = 'scf_';
    private $post;
    private $error;
    public function addError($code, $message, $data = '')
    {
        if (!$this->isError()) {
            $e = new WP_Error();
            $this->error = $e;
        }
        $this->error->add($code, $message, $data);
    }
    public function getError()
    {
        if (!$this->isError()) {
            $e = new WP_Error();
            return $e;
        }
        return $this->error;
    }
    public function isError()
    {
        return is_wp_error($this->error);
    }
    protected function setPost($post_id)
    {
        $post = get_post($post_id);
        if (is_object($post)) {
            $this->post = $post;
        } else {
            $this->addError('post_id_not_found', ('Provided Post ID not found.'));
        }
    }
    public function getPost()
    {
        return $this->post;
    }
    public static function getByID($post_id)
    {
        $object = new RSCSV_Import_Post_Helper();
        $object->setPost($post_id);
        return $object;
    }
    public static function add($data)
    {
        $object = new RSCSV_Import_Post_Helper();

        if ($data['post_type'] == 'attachment') {
            $post_id = $object->addMediaFile($data['media_file'], $data);
        } else {
            $post_id = wp_insert_post($data, true);
        }
        if (is_wp_error($post_id)) {
            $object->addError($post_id->get_error_code(), $post_id->get_error_message());
        } else {
            $object->setPost($post_id);
        }
        return $object;
    }
    public function update($data)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            $data['ID'] = $post->ID;
        }
        if ($data['post_type'] == 'attachment' && !empty($data['media_file'])) {
            $this->updateAttachment($data['media_file']);
            unset($data['media_file']);
        }
        $post_id = wp_update_post($data, true);
        if (is_wp_error($post_id)) {
            $this->addError($post_id->get_error_code(), $post_id->get_error_message());
        } else {
            $this->setPost($post_id);
        }
    }
    public function setMeta($data)
    {
        $scf_array = array();
        foreach ($data as $key => $value) {
            $is_cfs = 0;
            $is_scf = 0;
            $is_acf = 0;
            if (strpos($key, self::CFS_PREFIX) === 0) {
                $this->cfsSave(substr($key, strlen(self::CFS_PREFIX)), $value);
                $is_cfs = 1;
            } elseif(strpos($key, self::SCF_PREFIX) === 0) {
                $scf_key = substr($key, strlen(self::SCF_PREFIX));
                $scf_array[$scf_key][] = $value;
                $is_scf = 1;
            } else {
                if (function_exists('get_field_object')) {
                    if (strpos($key, 'field_') === 0) {
                        $fobj = get_field_object($key);
                        if (is_array($fobj) && isset($fobj['key']) && $fobj['key'] == $key) {
                            $this->acfUpdateField($key, $value);
                            $is_acf = 1;
                        }
                    }
                }
            }
            if (!$is_acf && !$is_cfs && !$is_scf) {
                $this->updateMeta($key, $value);
            }
        }
        $this->scfSave($scf_array);
    }
    
    protected function updateMeta($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            update_post_meta($post->ID, $key, $value);
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }
    protected function acfUpdateField($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (function_exists('update_field')) {
                update_field($key, $value, $post->ID);
            } else {
                $this->updateMeta($key, $value);
            }
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }
    
    protected function cfsSave($key, $value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (function_exists('CFS')) {
                $field_data = array($key => $value);
                $post_data = array('ID' => $post->ID);
                CFS()->save($field_data, $post_data);
            } else {
                $this->updateMeta($key, $value);
            }
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }
    protected function scfSave($data)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (class_exists('Smart_Custom_Fields_Meta') && is_array($data)) {
                $_data = array();
                $_data['smart-custom-fields'] = $data;
                $meta = new Smart_Custom_Fields_Meta($post);
                $meta->save($_data);
            } elseif(is_array($data)) {
                foreach ($data as $key => $array) {
                    foreach ((array) $array as $value) {
                        $this->updateMeta($key, $value);
                    }
                }
            }
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }
    public function setPostTags($tags)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            wp_set_post_tags($post->ID, $tags);
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }
    public function setObjectTerms($taxonomy, $terms)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            wp_set_object_terms($post->ID, $terms, $taxonomy);
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }
    
    public function addMediaFile($file, $data = null)
    {
        if (parse_url($file, PHP_URL_SCHEME)) {
            $file = $this->remoteGet($file);
        }
        $id = $this->setAttachment($file, $data);
        if ($id) {
            return $id;
        }
        
        return false;
    }
    
    public function addThumbnail($file)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            if (parse_url($file, PHP_URL_SCHEME)) {
                $file = $this->remoteGet($file);
            }
            $thumbnail_id = $this->setAttachment($file);
            if ($thumbnail_id) {
                $meta_id = set_post_thumbnail($post, $thumbnail_id);
                if ($meta_id) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    public function setAttachment($file, $data = array())
    {
        $post = $this->getPost();
        if ( $file && file_exists($file) ) {
            $filename       = basename($file);
            $wp_filetype    = wp_check_filetype_and_ext($file, $filename);
            $ext            = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
            $type           = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
            $proper_filename= empty( $wp_filetype['proper_filename'] ) ? '' : $wp_filetype['proper_filename'];
            $filename       = ($proper_filename) ? $proper_filename : $filename;
            $filename       = sanitize_file_name($filename);

            $upload_dir     = wp_upload_dir();
            $guid           = $upload_dir['baseurl'] . '/' . _wp_relative_upload_path($file);

            $attachment = array_merge(array(
                'post_mime_type'    => $type,
                'guid'              => $guid,
                'post_title'        => $filename,
                'post_content'      => '',
                'post_status'       => 'inherit'
            ), $data);
            $attachment_id          = wp_insert_attachment($attachment, $file, ($post instanceof WP_Post) ? $post->ID : null);
            $attachment_metadata    = wp_generate_attachment_metadata( $attachment_id, $file );
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
            return $attachment_id;
        }
        return 0;
    }

    protected function updateAttachment($value)
    {
        $post = $this->getPost();
        if ($post instanceof WP_Post) {
            update_attached_file($post->ID, $value);
        } else {
            $this->addError('post_is_not_set', ('WP_Post object is not set.'));
        }
    }

    public function remoteGet($url, $args = array())
    {
        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            WP_Filesystem();
        }
        
        if ($url && is_object($wp_filesystem)) {
            $response = wp_safe_remote_get($url, $args);
            if (!is_wp_error($response) && $response['response']['code'] === 200) {
                $destination = wp_upload_dir();
                $filename = basename($url);
                $filepath = $destination['path'] . '/' . wp_unique_filename($destination['path'], $filename);
                
                $body = wp_remote_retrieve_body($response);
                
                if ( $body && $wp_filesystem->put_contents($filepath , $body, FS_CHMOD_FILE) ) {
                    return $filepath;
                } else {
                    $this->addError('remote_get_failed', ('Could not get remote file.'));
                }
            } elseif (is_wp_error($response)) {
                $this->addError($response->get_error_code(), $response->get_error_message());
            }
        }
        
        return '';
    }
    public function __destruct()
    {
        unset($this->post);
    }
}
