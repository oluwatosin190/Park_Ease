<?php
// includes/MailchimpService.php

require_once __DIR__ . '/../config/mailchimp.php';

class MailchimpService {
    private $api_key;
    private $server_prefix;
    private $audience_id;
    private $api_url;
    
    public function __construct() {
        $this->api_key = MAILCHIMP_API_KEY;
        $this->server_prefix = MAILCHIMP_SERVER_PREFIX;
        $this->audience_id = MAILCHIMP_AUDIENCE_ID;
        $this->api_url = MAILCHIMP_API_URL;
    }
    
    /**
     * Subscribe an email to Mailchimp
     * 
     * @param string $email The email to subscribe
     * @param array $merge_fields Additional fields (FNAME, LNAME, etc.)
     * @param array $tags Tags to add to the subscriber
     * @return array Response from Mailchimp
     */
    public function subscribe($email, $merge_fields = [], $tags = []) {
        $url = $this->api_url . 'lists/' . $this->audience_id . '/members';
        
        $data = [
            'email_address' => $email,
            'status' => 'subscribed',
            'merge_fields' => $merge_fields,
            'tags' => $tags
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $this->api_key);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        
        $response = json_decode($result, true);
        
        // Mailchimp returns 200 for existing subscribers, 200 for new subscribers
        if ($http_code == 200) {
            return ['success' => true, 'message' => 'Already subscribed', 'data' => $response];
        } elseif ($http_code == 400 && isset($response['title']) && $response['title'] == 'Member Exists') {
            // Update existing subscriber to subscribed status
            return $this->updateSubscriberStatus($email, 'subscribed');
        } elseif ($http_code >= 200 && $http_code < 300) {
            return ['success' => true, 'message' => 'Successfully subscribed', 'data' => $response];
        } else {
            return ['success' => false, 'error' => $response['detail'] ?? 'Unknown error', 'data' => $response];
        }
    }
    
    /**
     * Update subscriber status (subscribe/unsubscribe)
     * 
     * @param string $email The email to update
     * @param string $status 'subscribed' or 'unsubscribed'
     * @return array Response
     */
    public function updateSubscriberStatus($email, $status) {
        $subscriber_hash = md5(strtolower($email));
        $url = $this->api_url . 'lists/' . $this->audience_id . '/members/' . $subscriber_hash;
        
        $data = [
            'status' => $status
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $this->api_key);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code >= 200 && $http_code < 300) {
            return ['success' => true, 'message' => 'Status updated'];
        } else {
            return ['success' => false, 'error' => 'Failed to update status'];
        }
    }
    
    /**
     * Unsubscribe an email
     * 
     * @param string $email The email to unsubscribe
     * @return array Response
     */
    public function unsubscribe($email) {
        return $this->updateSubscriberStatus($email, 'unsubscribed');
    }
    
    /**
     * Check if an email is subscribed
     * 
     * @param string $email The email to check
     * @return array Response with status
     */
    public function checkSubscription($email) {
        $subscriber_hash = md5(strtolower($email));
        $url = $this->api_url . 'lists/' . $this->audience_id . '/members/' . $subscriber_hash;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $this->api_key);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $data = json_decode($result, true);
            return ['success' => true, 'subscribed' => ($data['status'] == 'subscribed'), 'data' => $data];
        } else {
            return ['success' => false, 'subscribed' => false];
        }
    }
    
    /**
     * Get all subscribers (paginated)
     * 
     * @param int $count Number of subscribers to fetch
     * @param int $offset Offset for pagination
     * @return array List of subscribers
     */
    public function getSubscribers($count = 100, $offset = 0) {
        $url = $this->api_url . 'lists/' . $this->audience_id . '/members?count=' . $count . '&offset=' . $offset;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $this->api_key);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            return json_decode($result, true);
        } else {
            return ['members' => [], 'total_items' => 0];
        }
    }
    
    /**
     * Add tags to a subscriber
     * 
     * @param string $email The email to tag
     * @param array $tags Array of tags to add
     * @return array Response
     */
    public function addTags($email, $tags) {
        $subscriber_hash = md5(strtolower($email));
        $url = $this->api_url . 'lists/' . $this->audience_id . '/members/' . $subscriber_hash . '/tags';
        
        $tag_data = [];
        foreach ($tags as $tag) {
            $tag_data[] = ['name' => $tag, 'status' => 'active'];
        }
        
        $data = ['tags' => $tag_data];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, 'user:' . $this->api_key);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 204) {
            return ['success' => true, 'message' => 'Tags added'];
        } else {
            return ['success' => false, 'error' => 'Failed to add tags'];
        }
    }
}
?>