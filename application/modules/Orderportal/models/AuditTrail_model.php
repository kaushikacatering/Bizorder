<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Patient Audit Trail Model
 * Handles all audit logging for patient events: Onboarding, Discharge, Room Transfers
 * 
 * This model provides:
 * - Event logging for all patient actions
 * - Query methods for audit reports
 * - Time tracking for compliance and reporting
 */
class AuditTrail_model extends CI_Model {
    
    protected $tenantDb;
    
    public function __construct() {
        parent::__construct();
        
        // Get tenant database connection from MY_Controller
        $this->tenantDb = $this->load->database('default', TRUE);
        
        // Try to get the tenant database from the controller
        $CI =& get_instance();
        if (isset($CI->tenantDb)) {
            $this->tenantDb = $CI->tenantDb;
        }
    }
    
    /**
     * Log a patient event to the audit trail
     * 
     * @param string $eventType One of: 'onboarding', 'discharge', 'transfer', 'update', 'reactivate'
     * @param array $data Event data
     * @return int|bool Insert ID on success, false on failure
     */
    public function logEvent($eventType, $data) {
        // Ensure the audit table exists before attempting to insert
        if (!$this->ensureAuditTableExists()) {
            log_message('error', "AUDIT LOG FAILED: Could not create/verify patient_audit_log table for {$eventType} event");
            return false;
        }
        
        $this->load->helper('custom');
        
        // Get current Australia time
        $australiaTime = australia_datetime();
        $australiaDate = australia_date_only();
        
        // Get current user info
        $CI =& get_instance();
        $userId = $CI->session->userdata('user_id');
        $userName = $CI->session->userdata('username');
        
        if (empty($userName) && $CI->ion_auth->logged_in()) {
            $user = $CI->ion_auth->user()->row();
            $userId = $user->id ?? null;
            $userName = ($user->first_name ?? '') . ' ' . ($user->last_name ?? '');
            if (empty(trim($userName))) {
                $userName = $user->email ?? 'Unknown User';
            }
        }
        
        // Build comprehensive JSON data payload for audit logging
        $jsonPayload = array(
            'event_type' => $eventType,
            'timestamp' => $australiaTime,
            'patient' => array(
                'id' => $data['patient_id'] ?? 0,
                'name' => $data['patient_name'] ?? 'Unknown'
            ),
            'location' => array(
                'old_suite' => array(
                    'id' => $data['old_suite_id'] ?? null,
                    'number' => $data['old_suite_number'] ?? null,
                    'floor_id' => $data['old_floor_id'] ?? null,
                    'floor_name' => $data['old_floor_name'] ?? null
                ),
                'new_suite' => array(
                    'id' => $data['new_suite_id'] ?? null,
                    'number' => $data['new_suite_number'] ?? null,
                    'floor_id' => $data['new_floor_id'] ?? null,
                    'floor_name' => $data['new_floor_name'] ?? null
                )
            ),
            'impact' => array(
                'orders_affected' => $data['orders_affected'] ?? 0,
                'meals_cancelled' => $data['meals_cancelled'] ?? 0
            ),
            'notes' => $data['notes'] ?? null,
            'performed_by' => array(
                'user_id' => $userId,
                'username' => $userName,
                'ip_address' => $CI->input->ip_address()
            )
        );
        
        $auditData = array(
            'patient_id' => $data['patient_id'] ?? 0,
            'patient_name' => $data['patient_name'] ?? 'Unknown',
            'event_type' => $eventType,
            'event_datetime' => $australiaTime,
            'event_date' => $australiaDate,
            'old_suite_id' => $data['old_suite_id'] ?? null,
            'old_suite_number' => $data['old_suite_number'] ?? null,
            'old_floor_id' => $data['old_floor_id'] ?? null,
            'old_floor_name' => $data['old_floor_name'] ?? null,
            'new_suite_id' => $data['new_suite_id'] ?? null,
            'new_suite_number' => $data['new_suite_number'] ?? null,
            'new_floor_id' => $data['new_floor_id'] ?? null,
            'new_floor_name' => $data['new_floor_name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'orders_affected' => $data['orders_affected'] ?? 0,
            'meals_cancelled' => $data['meals_cancelled'] ?? 0,
            'created_by' => $userId,
            'created_by_name' => $userName,
            'ip_address' => $CI->input->ip_address(),
        );
        
        // Only include json_data if the column exists in the table
        // (The column may not exist in older schemas — omitting it prevents silent INSERT failures)
        if ($this->tenantDb->field_exists('json_data', 'patient_audit_log')) {
            $auditData['json_data'] = json_encode($jsonPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        
        $result = $this->tenantDb->insert('patient_audit_log', $auditData);
        
        if ($result) {
            $insertId = $this->tenantDb->insert_id();
            log_message('info', "AUDIT LOG: {$eventType} event logged for patient ID {$data['patient_id']}. Audit ID: {$insertId}. JSON: " . json_encode($jsonPayload));
            return $insertId;
        }
        
        // Log the actual DB error for debugging
        $dbError = $this->tenantDb->error();
        log_message('error', "AUDIT LOG FAILED: Failed to log {$eventType} event for patient ID " . ($data['patient_id'] ?? 'UNKNOWN') . ". DB Error: " . json_encode($dbError));
        return false;
    }
    
    /**
     * Log patient onboarding event
     */
    public function logOnboarding($patientId, $patientName, $suiteId, $suiteNumber, $floorId, $floorName, $notes = null) {
        return $this->logEvent('onboarding', array(
            'patient_id' => $patientId,
            'patient_name' => $patientName,
            'new_suite_id' => $suiteId,
            'new_suite_number' => $suiteNumber,
            'new_floor_id' => $floorId,
            'new_floor_name' => $floorName,
            'notes' => $notes
        ));
    }
    
    /**
     * Log patient discharge event
     */
    public function logDischarge($patientId, $patientName, $suiteId, $suiteNumber, $floorId, $floorName, $mealsCancelled = 0, $notes = null) {
        return $this->logEvent('discharge', array(
            'patient_id' => $patientId,
            'patient_name' => $patientName,
            'old_suite_id' => $suiteId,
            'old_suite_number' => $suiteNumber,
            'old_floor_id' => $floorId,
            'old_floor_name' => $floorName,
            'meals_cancelled' => $mealsCancelled,
            'notes' => $notes
        ));
    }
    
    /**
     * Log room transfer event
     */
    public function logTransfer($patientId, $patientName, 
                                $oldSuiteId, $oldSuiteNumber, $oldFloorId, $oldFloorName,
                                $newSuiteId, $newSuiteNumber, $newFloorId, $newFloorName,
                                $ordersAffected = 0, $notes = null) {
        return $this->logEvent('transfer', array(
            'patient_id' => $patientId,
            'patient_name' => $patientName,
            'old_suite_id' => $oldSuiteId,
            'old_suite_number' => $oldSuiteNumber,
            'old_floor_id' => $oldFloorId,
            'old_floor_name' => $oldFloorName,
            'new_suite_id' => $newSuiteId,
            'new_suite_number' => $newSuiteNumber,
            'new_floor_id' => $newFloorId,
            'new_floor_name' => $newFloorName,
            'orders_affected' => $ordersAffected,
            'notes' => $notes
        ));
    }
    
    /**
     * Get audit trail for a specific patient
     */
    public function getPatientAuditTrail($patientId) {
        return $this->tenantDb
            ->where('patient_id', $patientId)
            ->order_by('event_datetime', 'DESC')
            ->get('patient_audit_log')
            ->result_array();
    }
    
    /**
     * Get audit events by date range
     */
    public function getEventsByDateRange($fromDate, $toDate, $eventType = null) {
        $this->tenantDb->where('event_date >=', $fromDate);
        $this->tenantDb->where('event_date <=', $toDate);
        
        if ($eventType) {
            $this->tenantDb->where('event_type', $eventType);
        }
        
        return $this->tenantDb
            ->order_by('event_datetime', 'DESC')
            ->get('patient_audit_log')
            ->result_array();
    }
    
    /**
     * Get all onboarding events in date range
     */
    public function getOnboardingReport($fromDate, $toDate) {
        return $this->getEventsByDateRange($fromDate, $toDate, 'onboarding');
    }
    
    /**
     * Get all discharge events in date range
     */
    public function getDischargeReport($fromDate, $toDate) {
        return $this->getEventsByDateRange($fromDate, $toDate, 'discharge');
    }
    
    /**
     * Get all transfer events in date range
     */
    public function getTransferReport($fromDate, $toDate) {
        return $this->getEventsByDateRange($fromDate, $toDate, 'transfer');
    }
    
    /**
     * Get combined audit report with all event types
     */
    public function getFullAuditReport($fromDate, $toDate) {
        return $this->tenantDb
            ->select('pal.*, p.status as current_patient_status')
            ->from('patient_audit_log pal')
            ->join('people p', 'p.id = pal.patient_id', 'left')
            ->where('pal.event_date >=', $fromDate)
            ->where('pal.event_date <=', $toDate)
            ->order_by('pal.event_datetime', 'DESC')
            ->get()
            ->result_array();
    }
    
    /**
     * Get summary statistics for audit events
     */
    public function getAuditSummary($fromDate, $toDate) {
        $sql = "SELECT 
                    event_type,
                    COUNT(*) as event_count,
                    SUM(meals_cancelled) as total_meals_cancelled,
                    SUM(orders_affected) as total_orders_affected
                FROM patient_audit_log
                WHERE event_date >= ? AND event_date <= ?
                GROUP BY event_type
                ORDER BY event_count DESC";
        
        $query = $this->tenantDb->query($sql, [$fromDate, $toDate]);
        return $query->result_array();
    }
    
    /**
     * Get events for a specific suite
     */
    public function getSuiteHistory($suiteId) {
        return $this->tenantDb
            ->group_start()
                ->where('old_suite_id', $suiteId)
                ->or_where('new_suite_id', $suiteId)
            ->group_end()
            ->order_by('event_datetime', 'DESC')
            ->get('patient_audit_log')
            ->result_array();
    }
    
    /**
     * Check if audit table exists (for graceful degradation)
     */
    public function auditTableExists() {
        return $this->tenantDb->table_exists('patient_audit_log');
    }
    
    /**
     * Ensure patient_audit_log table exists - create if missing
     * This is called before any insert to guarantee the table is available
     */
    public function ensureAuditTableExists() {
        if ($this->tenantDb->table_exists('patient_audit_log')) {
            return true;
        }
        
        log_message('info', 'AUDIT LOG: patient_audit_log table not found. Creating table...');
        
        $sql = "CREATE TABLE IF NOT EXISTS `patient_audit_log` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `patient_id` INT(11) UNSIGNED DEFAULT NULL,
            `patient_name` VARCHAR(255) DEFAULT NULL,
            `event_type` VARCHAR(50) NOT NULL COMMENT 'onboarding, discharge, transfer',
            `event_datetime` DATETIME DEFAULT NULL,
            `event_date` DATE DEFAULT NULL,
            `old_suite_id` INT(11) UNSIGNED DEFAULT NULL,
            `old_suite_number` VARCHAR(100) DEFAULT NULL,
            `old_floor_id` INT(11) UNSIGNED DEFAULT NULL,
            `old_floor_name` VARCHAR(255) DEFAULT NULL,
            `new_suite_id` INT(11) UNSIGNED DEFAULT NULL,
            `new_suite_number` VARCHAR(100) DEFAULT NULL,
            `new_floor_id` INT(11) UNSIGNED DEFAULT NULL,
            `new_floor_name` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `orders_affected` INT(11) DEFAULT 0,
            `meals_cancelled` INT(11) DEFAULT 0,
            `created_by` INT(11) UNSIGNED DEFAULT NULL,
            `created_by_name` VARCHAR(255) DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `json_data` LONGTEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_patient_id` (`patient_id`),
            KEY `idx_event_type` (`event_type`),
            KEY `idx_event_date` (`event_date`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $result = $this->tenantDb->query($sql);
        
        if ($result) {
            log_message('info', 'AUDIT LOG: patient_audit_log table created successfully');
            return true;
        } else {
            $error = $this->tenantDb->error();
            log_message('error', 'AUDIT LOG: Failed to create patient_audit_log table. Error: ' . json_encode($error));
            return false;
        }
    }
}
