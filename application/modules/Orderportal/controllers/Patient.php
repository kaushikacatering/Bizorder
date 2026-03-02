<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Patient extends MY_Controller
{
    public function __construct() 
    {   
      	parent::__construct();
   	     $this->load->model('configfoodmenu_model');
   	     $this->load->model('common_model');
   	      $this->load->model('menu_model');
       !$this->ion_auth->logged_in() ? redirect('auth/login', 'refresh') : '';
        $this->POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
        $this->selected_location_id = $this->session->userdata('default_location_id');
       
    }
    
  
    function Config(){
        
    }
    
    
    // for adding patient
    function addPatient(){
        
        $data['floor'] = array(); 
        $data['departments'] = array();
        
         $conditions['listtype'] = 'department';
         $conditions['is_deleted'] = '0';
         $departmentListData = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
        
        $conditions['listtype'] = 'floor';
        $conditions['is_deleted'] = '0';
        $floorListData = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
        $data['departments'] = $departmentListData;
        $data['floorLists'] = $floorListData;
      	$this->load->view('general/landingPageHeader');
        $this->load->view('Patient/addPatient',$data);
        $this->load->view('general/landingPageFooter');     
    }
    

	
	// new code start here ignore aobve code
	
	function Onboarding(){
	    

	$data['customerLists'] = $this->common_model->fetchRecordsDynamically('people'); 
	
    $conditions['listtype'] = 'floor';
    $conditions['is_deleted'] = '0';
    $data['floors']  = $this->common_model->fetchRecordsDynamically('foodmenuconfig',['id','name'],$conditions);
    
    
    $conditionsA['listtype'] = 'allergen';
    $conditionsA['is_deleted'] = '0';
    $data['allergies'] = $this->common_model->fetchRecordsDynamically('foodmenuconfig',['id','name'],$conditionsA);
    
    $conditionsB['status'] = '1';
    $conditionsB['is_deleted'] = '0';      // Exclude deleted suites
    $data['suites'] = $this->common_model->fetchRecordsDynamically('suites',['bed_no','id'],$conditionsB);
    // echo "<pre>"; print_r($data['customerLists']);exit;
    
    $this->load->view('general/landingPageHeader');
    $this->load->view('Patient/patientList',$data);
    $this->load->view('general/landingPageFooter');  
    
	}
	function onboardingForm($id='',$idType='person'){
	   
    $conditions['listtype'] = 'floor';
    $conditions['is_deleted'] = '0';
    $data['floor_numbers']  = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
    
     $conditionsA['listtype'] = 'allergen';
    $conditionsA['is_deleted'] = '0';
    $data['allergies'] = $this->common_model->fetchRecordsDynamically('foodmenuconfig',['id','name'],$conditionsA);
    
    // Fetch cuisines for dietary preferences
    $conditions_cuisine['listtype'] = 'cuisine';
    $conditions_cuisine['is_deleted'] = 0;
    $data['cuisines'] = $this->common_model->fetchRecordsDynamically('foodmenuconfig',['id','name'],$conditions_cuisine);
    
    // 🔒 CRITICAL: Determine if floor and suite fields should be enabled
    // Enable ONLY when: New patient from "Add Person" button (no ID, no idType='suite')
    // Disable when: Coming from suite list (idType='suite') OR editing existing patient (idType='person')
    $enableFloorAndSuite = (empty($id) && $idType != 'suite');
    $data['enableFloorAndSuite'] = $enableFloorAndSuite;
    
    $conditionsB['status'] = '1';
    $conditionsB['is_deleted'] = '0';  // Exclude deleted suites
    
    if($id !='' && $idType=='person'){
        // Editing existing patient - get patient details
        $conditionsP =  array('id' => $id);
        $patientDetails = $this->common_model->fetchRecordsDynamically('people','',$conditionsP); 
        $data['patientDetails'] = (isset($patientDetails[0]) && !empty($patientDetails) ? reset($patientDetails) : array());   
        $conditionsB['floor'] = $patientDetails[0]['floor_number'];
        // When editing, show only vacant suites OR current suite
        $conditionsB['is_vaccant'] = '1';  // Only show vacant/available suites
    } else {
        $data['patientDetails'] = array();
        
        if($id !='' && $idType =='suite'){
            // Coming from suite list - get suite details
            $data['selected_suite'] = $id;  
            $conditionsSuites['id'] = $id;
            $data['selectedFloor'] = $this->common_model->fetchRecordsDynamically('suites',['floor'],$conditionsSuites);
            $conditionsB['floor'] = $data['selectedFloor'][0]['floor'] ?? '';
        }
        
        // For new patient (Add Person), show all vacant suites (no floor filter initially)
        // For suite selection, show vacant suites for that floor
        $conditionsB['is_vaccant'] = '1';  // Only show vacant/available suites
    }
    
    // Get vacant suites
    $vacant_suites = $this->common_model->fetchRecordsDynamically('suites',['bed_no','id','floor'],$conditionsB);
    
    // If editing an existing patient, also include their current suite (even if occupied)
    if($id !='' && $idType=='person' && !empty($data['patientDetails']['suite_number'])){
        $current_suite_id = $data['patientDetails']['suite_number'];
        
        // Check if current suite is already in the list
        $suite_ids = array_column($vacant_suites, 'id');
        if(!in_array($current_suite_id, $suite_ids)){
            // Add current suite to the list
            $conditionsCurrentSuite = array(
                'id' => $current_suite_id,
                'status' => '1',
                'is_deleted' => '0'
            );
            $current_suite = $this->common_model->fetchRecordsDynamically('suites',['bed_no','id','floor'],$conditionsCurrentSuite);
            if(!empty($current_suite)){
                $vacant_suites = array_merge($current_suite, $vacant_suites);
            }
        }
    }
    
    $data['suites'] = $vacant_suites;
   
       	$this->load->view('general/landingPageHeader');
        $this->load->view('Patient/OnboardingForm',$data);
        $this->load->view('general/landingPageFooter');  
       
	}
	
	public function save_person() {
    // Load helper for Australia timezone functions
    $this->load->helper('custom');
    
    // Collect allergies as array
    $allergies = $this->input->post('allergies');
    
    // Save as JSON (preferred)
    $allergies_value = !empty($allergies) ? json_encode($allergies) : json_encode([]);
    
    // Collect dietary preferences (cuisines) as array
    $dietary_preferences = $this->input->post('dietary_preferences');
    if(empty($dietary_preferences)){
     $dietary_preferences = array('84');   // if no diet pref. is selected than we need to set  it to standard diet pref by default
    }
    // Save as JSON (preferred)
    
    $dietary_preferences_value = !empty($dietary_preferences) ? json_encode($dietary_preferences) : json_encode([]);

    $suite_number = $this->input->post('suite_number');
    $person_id = $this->input->post('personId');
    $discharge_date = $this->input->post('discharge_date');
    $onboard_date = $this->input->post('onboard_date');
    $patient_name = $this->input->post('name');
    $today = date('Y-m-d');
    
    // Log operation start
    $isNewPatient = empty($person_id);
    log_message('info', "PATIENT " . ($isNewPatient ? 'ONBOARD' : 'UPDATE') . ": Patient Name=" . ($patient_name ?: 'UNKNOWN') . ", Person ID=" . ($person_id ?: 'NEW') . ", Suite Number=" . ($suite_number ?: 'NONE') . ", Onboard Date=" . ($onboard_date ?: 'NONE') . ", Discharge Date=" . ($discharge_date ?: 'NONE') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());

    // Server-side validation: Check for duplicate active patients in same suite (for new patients only)
    if (empty($person_id) && !empty($suite_number)) {
        $existing_conditions = array(
            'suite_number' => $suite_number,
            'status' => 1 // Active patients only
        );
        $existing_patients = $this->common_model->fetchRecordsDynamically('people', '', $existing_conditions);
        
        // Filter out patients with past discharge dates (keep same-day patients as active)
        $active_patients = array();
        if (!empty($existing_patients)) {
            foreach ($existing_patients as $patient) {
                $patient_discharge = $patient['date_of_discharge'];
                // Keep patients active if discharge date is today or in the future
                if (empty($patient_discharge) || $patient_discharge >= $today) {
                    $active_patients[] = $patient;
                }
            }
        }
        
        if (!empty($active_patients)) {
            log_message('warning', "PATIENT ONBOARD BLOCKED: Suite {$suite_number} is already occupied by another active patient. Patient Name=" . ($patient_name ?: 'UNKNOWN') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            $this->session->set_flashdata('error_msg', 'This suite is already occupied by another client. Please select a different suite.');
            redirect('Orderportal/Patient/onboardingForm/' . $suite_number . '/suite');
            return;
        }
    }

    // Handle patient photo upload
    $photo_path = $this->input->post('existing_photo_path'); // Keep existing photo by default
    
    if (!empty($_FILES['patient_photo']['name'])) {
        // Configure upload settings
        $upload_path = './uploaded_files/patient_photos/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_path)) {
            mkdir($upload_path, 0755, true);
        }
        
        $config['upload_path'] = $upload_path;
        $config['allowed_types'] = 'jpg|jpeg|png';
        $config['max_size'] = 2048; // 2MB in KB
        $config['encrypt_name'] = TRUE; // Encrypt filename for security
        
        $this->load->library('upload', $config);
        
        if ($this->upload->do_upload('patient_photo')) {
            $upload_data = $this->upload->data();
            $photo_path = 'uploaded_files/patient_photos/' . $upload_data['file_name'];
            
            // Delete old photo if exists and is different from new one
            $old_photo = $this->input->post('existing_photo_path');
            if (!empty($old_photo) && file_exists('./' . $old_photo)) {
                @unlink('./' . $old_photo);
            }
        } else {
            // Upload failed - set error message
            $upload_error = $this->upload->display_errors('', '');
            log_message('error', "PATIENT " . ($isNewPatient ? 'ONBOARD' : 'UPDATE') . " PHOTO UPLOAD FAILED: " . $upload_error . ". Patient Name=" . ($patient_name ?: 'UNKNOWN') . ", Person ID=" . ($person_id ?: 'NEW') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            $this->session->set_flashdata('error_msg', 'Photo upload failed: ' . $upload_error);
            redirect('Orderportal/Patient/onboardingForm/' . ($person_id ?: $suite_number . '/suite'));
            return;
        }
    }
    
    $save_data = [
        'name' => $this->input->post('name'),
        'floor_number' => $this->input->post('floor_number'),
        'suite_number' => $suite_number,
        'allergies' => $allergies_value,
        'dietary_preferences' => $dietary_preferences_value,
        'special_instructions' => $this->input->post('instructions'),
        'photo_path' => $photo_path,
        'date_onboarded' => $onboard_date,
        'date_of_discharge' => $discharge_date ?: NULL,
        'status' => 1 // Default to active status
    ];

    // Improved discharge date logic
    $should_be_discharged = false;
    
    if (!empty($discharge_date)) {
        // Compare dates properly using strtotime for accurate comparison
        $discharge_timestamp = strtotime($discharge_date);
        $today_timestamp = strtotime($today);
        $onboard_timestamp = strtotime($onboard_date);
        
        // If discharge date is in the past (before today), patient should be discharged
        // If discharge date is same as onboard date AND same as today, keep patient active for the day
        // Only discharge if discharge date is actually in the past
        if ($discharge_timestamp < $today_timestamp) {
            $should_be_discharged = true;
        }
        // Special case: if discharge date is today but onboard date is in the past, discharge
        elseif ($discharge_timestamp == $today_timestamp && $onboard_timestamp < $today_timestamp) {
            $should_be_discharged = true;
        }
    }
    
    if ($should_be_discharged) {
        // Mark suite as vacant and patient as discharged
        $bedData['is_vaccant'] = 1;
        $save_data['status'] = 2; // Set patient status to discharged
    } else {
        // Mark suite as occupied (only if patient is active)
        $bedData['is_vaccant'] = 0;
    }
    
    // IMPORTANT: If updating an existing patient, check if suite number changed
    $old_suite_number = null;
    $old_patient_status = null;
    if (!empty($person_id)) {
        // Get the patient's current suite number and status before updating
        $current_patient = $this->common_model->fetchRecordsDynamically('people', ['suite_number', 'status'], ['id' => $person_id]);
        if (!empty($current_patient)) {
            $old_suite_number = $current_patient[0]['suite_number'];
            $old_patient_status = $current_patient[0]['status'];
        }
    }
    
    // Update NEW suite status
    $this->common_model->commonRecordUpdate('suites','id',$suite_number,$bedData);  

    // If patient moved from one suite to another AND was previously active (occupied), mark the OLD suite as vacant
    if (!empty($old_suite_number) && $old_suite_number != $suite_number && $old_patient_status == 1) {
        $old_suite_data['is_vaccant'] = 1; // Mark old suite as vacant
        $this->common_model->commonRecordUpdate('suites','id',$old_suite_number,$old_suite_data);
        log_message('info', "SUITE STATUS UPDATE: Marked old suite {$old_suite_number} as vacant after moving active patient to suite {$suite_number}. Patient Name=" . ($patient_name ?: 'UNKNOWN') . ", Person ID={$person_id}, User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
        
        // ═══════════════════════════════════════════════════════════════════
        // TRANSFER ORDERS: Move all future/active orders from old suite to new suite
        // This ensures the patient's meal orders follow them to their new room
        // ═══════════════════════════════════════════════════════════════════
        $ordersTransferred = $this->transferSuiteOrders($old_suite_number, $suite_number, $patient_name ?: 'Unknown');
        if ($ordersTransferred > 0) {
            log_message('info', "ORDER TRANSFER: Transferred {$ordersTransferred} order(s) during patient edit from suite {$old_suite_number} to {$suite_number}. Patient: {$patient_name}");
        }
        
        // LOG TO AUDIT TRAIL: Room Transfer
        $this->logTransferToAuditTrail($person_id, $patient_name, $old_suite_number, $suite_number, $ordersTransferred);
    }

    // Check if dietary/allergens changed (for update notifications)
    $dietaryOrAllergenChanged = false;
    if (!empty($person_id)) {
        // Get old patient data to compare
        $oldPatient = $this->common_model->fetchRecordsDynamically('people', ['allergies', 'dietary_preferences'], ['id' => $person_id]);
        if (!empty($oldPatient)) {
            $oldAllergies = $oldPatient[0]['allergies'] ?? '';
            $oldDietary = $oldPatient[0]['dietary_preferences'] ?? '';
            // Check if allergies or dietary preferences changed
            if ($oldAllergies !== $allergies_value || $oldDietary !== $dietary_preferences_value) {
                $dietaryOrAllergenChanged = true;
            }
        }
    }
    
    // Save or update patient record
    if (empty($person_id)) {
        $save_data['date_added'] = australia_datetime();
        $save_data['time_onboarded'] = australia_datetime(); // EXACT TIME of onboarding entry
        $actionid = $this->common_model->commonRecordCreate('people',$save_data);
        
        log_message('info', "PATIENT ONBOARD SUCCESS: Patient ID={$actionid}, Patient Name=" . ($patient_name ?: 'UNKNOWN') . ", Suite Number={$suite_number}, Onboard Date={$onboard_date}, Discharge Date=" . ($discharge_date ?: 'NONE') . ", Status=" . ($should_be_discharged ? 'DISCHARGED' : 'ACTIVE') . ", Suite Status=" . ($should_be_discharged ? 'VACANT' : 'OCCUPIED') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());
        
        // LOG TO AUDIT TRAIL: Patient Onboarded
        $this->logOnboardingToAuditTrail($actionid, $save_data, $suite_number, $should_be_discharged);
        
        // SIMPLIFIED NOTIFICATION 1: Suite Added - New patient onboarded
        $patientName = $save_data['name'] ?: 'Unknown Patient';
        $suiteNo = $save_data['suite_number'] ?: 'Unknown Suite';
        
        // SIMPLIFIED: Suite Added notification (timestamp below shows when created)
        $msg = "Suite Added: Suite {$suiteNo} - onboarded new patient {$patientName}";
        $this->load->helper('notification');
        createNotification($this->tenantDb, 1, $this->selected_location_id, 'alert', $msg);
    } else {
        $save_data['date_modified'] = australia_datetime();
        $this->common_model->commonRecordUpdate('people','id',$person_id,$save_data);
        
        log_message('info', "PATIENT UPDATE SUCCESS: Patient ID={$person_id}, Patient Name=" . ($patient_name ?: 'UNKNOWN') . ", Suite Number={$suite_number}, Old Suite Number=" . ($old_suite_number ?: 'NONE') . ", Onboard Date={$onboard_date}, Discharge Date=" . ($discharge_date ?: 'NONE') . ", Status=" . ($should_be_discharged ? 'DISCHARGED' : 'ACTIVE') . ", Suite Status=" . ($should_be_discharged ? 'VACANT' : 'OCCUPIED') . ", Dietary/Allergen Changed=" . ($dietaryOrAllergenChanged ? 'YES' : 'NO') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());
        
        // SIMPLIFIED NOTIFICATION 2: Patient Updated - Only if dietary/allergens changed
        if ($dietaryOrAllergenChanged) {
            $patientName = $save_data['name'] ?: 'Unknown Patient';
            
            // SIMPLIFIED: Patient Updated notification (timestamp below shows when created)
            $msg = "Patient Updated: {$patientName} - dietary or allergens updated";
            $this->load->helper('notification');
            createNotification($this->tenantDb, 1, $this->selected_location_id, 'notice', $msg);
        }
    }
    
    // Set success message with debug info
    $action = empty($person_id) ? 'onboarded' : 'updated';
    $status_text = $should_be_discharged ? 'discharged' : 'active';
    $suite_status = $should_be_discharged ? 'vacant' : 'occupied';
    
    $this->session->set_flashdata('sucess_msg', 'Client ' . $action . ' successfully! Status: ' . $status_text . ', Suite: ' . $suite_status);
    
    // Get current user role
    $userRole = $this->ion_auth->get_users_groups()->row()->id;
    
    // Redirect based on user role
    // Nurses (role 3) and Chefs (role 5) go back to suites page
    if ($userRole == 3 || $userRole == 5) {
        redirect('/Orderportal/Hospitalconfig/List');
    } else {
        // Other roles (admin, reception) go to onboarding list
        redirect('Orderportal/Patient/Onboarding');
    }
}

    
    public function is_suite_occupied() {
        
       $suite_number =  $this->input->post('suite_number');
       $conditions = array('id' => $suite_number,  'is_vaccant' => 0); 
        $result = $this->common_model->fetchRecordsDynamically('suites',['id'],$conditions);

       echo json_encode($result);
    }
    
    public function getbedno() {
    $floor_id = $this->input->post('floor_id');

    $conditionsB['floor'] = $floor_id;
    $conditionsB['status'] = '1';          // Only active suites
    $conditionsB['is_deleted'] = '0';      // Exclude deleted suites
    $conditionsB['is_vaccant'] = '1';      // Only show vacant/available suites
    $suites = $this->common_model->fetchRecordsDynamically('suites',['bed_no','id','floor'],$conditionsB);

    echo json_encode($suites);
}

    /**
     * Update patient status (active/discharged)
     * Called via AJAX from patient list discharge toggle
     * 
     * UPDATED: Now includes time tracking and audit trail logging
     * FIXED: Now cancels same-day orders based on discharge time
     */
    public function updateStatus() {
        $this->load->helper('custom'); // Load custom helper for Australia timezone functions
        
        $patient_id = $this->input->post('id');
        $status = $this->input->post('status');
        
        // Validate input
        if (empty($patient_id)) {
            log_message('error', "PATIENT STATUS UPDATE FAILED: No patient ID provided. User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Patient ID is required']);
            return;
        }
        
        if (!in_array($status, ['active', 'discharged'])) {
            log_message('error', "PATIENT STATUS UPDATE FAILED: Invalid status={$status}. Patient ID={$patient_id}, User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
            return;
        }
        
        // Convert status to numeric value
        // 1 = active, 2 = discharged
        $status_value = ($status == 'discharged') ? 2 : 1;
        
        // Get patient details to update suite if being discharged
        $patient_conditions = array('id' => $patient_id);
        $patient_details = $this->common_model->fetchRecordsDynamically('people', '', $patient_conditions);
        
        if (empty($patient_details)) {
            log_message('error', "PATIENT STATUS UPDATE FAILED: Patient ID={$patient_id} not found. User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Patient not found']);
            return;
        }
        
        $patient = $patient_details[0];
        $old_status = $patient['status'];
        
        log_message('info', "PATIENT STATUS UPDATE: Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", Old Status=" . ($old_status == 1 ? 'ACTIVE' : ($old_status == 2 ? 'DISCHARGED' : 'UNKNOWN')) . ", New Status=" . strtoupper($status) . ", Suite Number=" . ($patient['suite_number'] ?: 'NONE') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());
        
        // Update patient status
        $update_data = array(
            'status' => $status_value,
            'date_modified' => australia_datetime()
        );
        
        // If discharging, set actual discharge date AND TIME if not already set
        if ($status == 'discharged') {
            if (empty($patient['date_of_discharge'])) {
                $update_data['date_of_discharge'] = australia_date_only();
            }
            // ALWAYS record the exact time of discharge entry
            $update_data['time_discharged'] = australia_datetime();
        }
        
        $result = $this->common_model->commonRecordUpdate('people', 'id', $patient_id, $update_data);
        
        if ($result) {
            $cancelled_count = 0;
            
            // If patient is being discharged, make their suite available
            if ($status == 'discharged' && !empty($patient['suite_number'])) {
                // IMMEDIATELY mark suite as vacant
                $suite_update = array('is_vaccant' => 1);
                $this->common_model->commonRecordUpdate('suites', 'id', $patient['suite_number'], $suite_update);
                log_message('info', "SUITE STATUS UPDATE: Suite {$patient['suite_number']} marked as VACANT after patient discharge. Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . " at " . australia_datetime());
                
                // Cancel all future AND same-day orders for this suite
                $cancelled_count = $this->cancelOrdersOnDischarge(
                    $patient['suite_number'], 
                    $patient['name'] ?: 'Unknown',
                    $patient_id
                );
                
                if ($cancelled_count > 0) {
                    log_message('info', "PATIENT DISCHARGE - ORDERS CANCELLED: {$cancelled_count} order item(s) cancelled for suite {$patient['suite_number']} after patient discharge. Patient ID={$patient_id}, User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . " at " . australia_datetime());
                }
                
                // Log to audit trail
                $this->logDischargeToAuditTrail($patient, $cancelled_count);
            }
            
            log_message('info', "PATIENT STATUS UPDATE SUCCESS: Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", Status changed to " . strtoupper($status) . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Client status updated successfully' . ($cancelled_count > 0 ? ". {$cancelled_count} meal order(s) cancelled." : ''),
                'new_status' => $status,
                'orders_cancelled' => $cancelled_count
            ]);
        } else {
            log_message('error', "PATIENT STATUS UPDATE FAILED: Database update failed for Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Failed to update client status']);
        }
    }
    
    /**
     * Log discharge event to audit trail
     */
    private function logDischargeToAuditTrail($patient, $mealsCancelled = 0) {
        // Try to load and use the audit trail model
        try {
            $this->load->model('AuditTrail_model');
            
            // Get suite and floor names
            $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $patient['suite_number']]);
            $suite_number = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite {$patient['suite_number']}";
            
            $floor_details = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], ['id' => $patient['floor_number'], 'listtype' => 'floor']);
            $floor_name = !empty($floor_details) ? $floor_details[0]['name'] : "Floor {$patient['floor_number']}";
            
            $this->AuditTrail_model->logDischarge(
                $patient['id'],
                $patient['name'] ?: 'Unknown',
                $patient['suite_number'],
                $suite_number,
                $patient['floor_number'],
                $floor_name,
                $mealsCancelled,
                'Patient discharged via status update'
            );
        } catch (Exception $e) {
            log_message('error', 'Failed to log discharge to audit trail: ' . $e->getMessage());
        }
    }
    
    /**
     * Log onboarding event to audit trail
     */
    private function logOnboardingToAuditTrail($patientId, $saveData, $suiteNumber, $isDischargedOnEntry = false) {
        try {
            $this->load->model('AuditTrail_model');
            
            // Get suite and floor names
            $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $suiteNumber]);
            $suite_name = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite {$suiteNumber}";
            
            $floor_details = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], ['id' => $saveData['floor_number'], 'listtype' => 'floor']);
            $floor_name = !empty($floor_details) ? $floor_details[0]['name'] : "Floor {$saveData['floor_number']}";
            
            $notes = $isDischargedOnEntry ? 'Patient onboarded with past discharge date - immediately set to discharged' : 'New patient onboarded';
            
            $this->AuditTrail_model->logOnboarding(
                $patientId,
                $saveData['name'] ?: 'Unknown',
                $suiteNumber,
                $suite_name,
                $saveData['floor_number'],
                $floor_name,
                $notes
            );
        } catch (Exception $e) {
            log_message('error', 'Failed to log onboarding to audit trail: ' . $e->getMessage());
        }
    }
    
    /**
     * Log room transfer event to audit trail
     */
    private function logTransferToAuditTrail($patientId, $patientName, $oldSuiteId, $newSuiteId, $ordersTransferred = 0) {
        try {
            $this->load->model('AuditTrail_model');
            
            // Get old suite details
            $old_suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no', 'floor_id'], ['id' => $oldSuiteId]);
            $old_suite_name = !empty($old_suite_details) ? $old_suite_details[0]['bed_no'] : "Suite {$oldSuiteId}";
            $old_floor_id = !empty($old_suite_details) ? $old_suite_details[0]['floor_id'] : null;
            
            $old_floor_details = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], ['id' => $old_floor_id, 'listtype' => 'floor']);
            $old_floor_name = !empty($old_floor_details) ? $old_floor_details[0]['name'] : "Floor {$old_floor_id}";
            
            // Get new suite details
            $new_suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no', 'floor_id'], ['id' => $newSuiteId]);
            $new_suite_name = !empty($new_suite_details) ? $new_suite_details[0]['bed_no'] : "Suite {$newSuiteId}";
            $new_floor_id = !empty($new_suite_details) ? $new_suite_details[0]['floor_id'] : null;
            
            $new_floor_details = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], ['id' => $new_floor_id, 'listtype' => 'floor']);
            $new_floor_name = !empty($new_floor_details) ? $new_floor_details[0]['name'] : "Floor {$new_floor_id}";
            
            $notes = "Transferred from {$old_suite_name} to {$new_suite_name}";
            if ($ordersTransferred > 0) {
                $notes .= ". {$ordersTransferred} meal order(s) updated to new room.";
            }
            
            $this->AuditTrail_model->logTransfer(
                $patientId,
                $patientName ?: 'Unknown',
                $oldSuiteId,
                $old_suite_name,
                $old_floor_id,
                $old_floor_name,
                $newSuiteId,
                $new_suite_name,
                $new_floor_id,
                $new_floor_name,
                $ordersTransferred,
                $notes
            );
            
            // Also send notification to kitchen about the room transfer
            $this->load->helper('notification');
            $msg = "🔄 Room Transfer: Patient '{$patientName}' moved from {$old_suite_name} to {$new_suite_name}. {$ordersTransferred} meal order(s) updated.";
            createNotification($this->tenantDb, 1, $this->selected_location_id, 'notice', $msg);
            
        } catch (Exception $e) {
            log_message('error', 'Failed to log transfer to audit trail: ' . $e->getMessage());
        }
    }
    
    /**
     * Cancel orders when patient is discharged
     * Handles BOTH same-day orders (based on time) AND future orders
     * 
     * SAME-DAY RULES:
     * - Before 11am: Cancel LUNCH + DINNER for today
     * - Before 2pm (after 11am): Cancel DINNER only for today
     * - After 2pm: No same-day cancellation (meals already served)
     * 
     * FUTURE ORDERS: All meals cancelled
     * 
     * @param int $suite_id The suite/bed ID
     * @param string $patient_name Patient name for notification
     * @param int $patient_id Patient ID for reference
     * @return int Number of order items cancelled
     */
    private function cancelOrdersOnDischarge($suite_id, $patient_name, $patient_id) {
        $this->load->helper('notification');
        $this->load->helper('custom');
        
        $cancelled_count = 0;
        $today = australia_date_only();
        
        // Get suite details for notification
        $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $suite_id]);
        $suite_name = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite $suite_id";
        
        // Get current Australia/Sydney time for same-day cancellation rules
        $australiaTime = new DateTime('now', new DateTimeZone('Australia/Sydney'));
        $currentHour = (int) $australiaTime->format('H');
        $currentMinute = (int) $australiaTime->format('i');
        
        // Category IDs from foodmenuconfig table
        $BREAKFAST_CATEGORY_ID = 3;
        $LUNCH_CATEGORY_ID = 5;
        $DINNER_CATEGORY_ID = 7;
        
        // Determine which categories to cancel for TODAY based on discharge time
        $categoriesToCancelToday = [];
        $sameDayCancelReason = '';
        
        if ($currentHour < 11) {
            // Before 11am - cancel LUNCH + DINNER for today
            $categoriesToCancelToday = [$LUNCH_CATEGORY_ID, $DINNER_CATEGORY_ID];
            $sameDayCancelReason = 'discharged_before_11am';
            log_message('info', "DISCHARGE TIME CHECK: Before 11am ($currentHour:$currentMinute) - Will cancel LUNCH + DINNER for today");
        } elseif ($currentHour < 14) {
            // Before 2pm (but after 11am) - cancel DINNER only for today
            $categoriesToCancelToday = [$DINNER_CATEGORY_ID];
            $sameDayCancelReason = 'discharged_before_2pm';
            log_message('info', "DISCHARGE TIME CHECK: Before 2pm ($currentHour:$currentMinute) - Will cancel DINNER only for today");
        } else {
            // 2pm or later - no same-day cancellation (meals already served)
            log_message('info', "DISCHARGE TIME CHECK: After 2pm ($currentHour:$currentMinute) - No same-day meal cancellation");
        }
        
        $notification_dates = [];
        $cancelled_meals = [];
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 1: CANCEL SAME-DAY ORDERS (Based on time rules)
        // ═══════════════════════════════════════════════════════════════════
        if (!empty($categoriesToCancelToday)) {
            $todayCancelledCount = $this->softCancelOrderItemsForSuite(
                $suite_id, 
                $today, 
                $categoriesToCancelToday, 
                $patient_name, 
                $suite_name, 
                $sameDayCancelReason
            );
            
            if ($todayCancelledCount > 0) {
                $cancelled_count += $todayCancelledCount;
                $notification_dates[] = date('d-m-Y', strtotime($today));
                
                // Build meal names for notification
                foreach ($categoriesToCancelToday as $catId) {
                    if ($catId == $LUNCH_CATEGORY_ID) $cancelled_meals[] = 'Lunch';
                    if ($catId == $DINNER_CATEGORY_ID) $cancelled_meals[] = 'Dinner';
                }
                
                log_message('info', "DISCHARGE SAME-DAY CANCEL: Cancelled $todayCancelledCount item(s) for suite $suite_name today (". implode(' & ', $cancelled_meals) .")");
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 2: CANCEL ALL FUTURE ORDERS (date > today)
        // ═══════════════════════════════════════════════════════════════════
        $this->tenantDb->select('DISTINCT o.order_id, o.date');
        $this->tenantDb->from('orders o');
        $this->tenantDb->join('orders_to_patient_options opo', 'opo.order_id = o.order_id', 'inner');
        $this->tenantDb->where('o.date >', $today);
        $this->tenantDb->where('o.status !=', 0);
        $this->tenantDb->where('opo.bed_id', $suite_id);
        $this->tenantDb->where('opo.is_cancelled', 0);
        
        $future_orders = $this->tenantDb->get()->result_array();
        
        if (!empty($future_orders)) {
            foreach ($future_orders as $order) {
                $order_id = $order['order_id'];
                $order_date = $order['date'];
                
                // Soft cancel ALL categories for future orders
                $futureCancelledCount = $this->softCancelOrderItemsForSuite(
                    $suite_id, 
                    $order_date, 
                    null, // null = all categories
                    $patient_name, 
                    $suite_name, 
                    'patient_discharged',
                    $order_id
                );
                
                if ($futureCancelledCount > 0) {
                    $cancelled_count += $futureCancelledCount;
                    $notification_dates[] = date('d-m-Y', strtotime($order_date));
                    
                    log_message('info', "DISCHARGE FUTURE CANCEL: Soft-cancelled $futureCancelledCount item(s) for suite $suite_name, order $order_id (date: $order_date)");
                }
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 3: SEND NOTIFICATION TO CHEF
        // ═══════════════════════════════════════════════════════════════════
        if ($cancelled_count > 0 && function_exists('createNotification')) {
            $unique_dates = array_unique($notification_dates);
            $dates_str = implode(', ', $unique_dates);
            
            $meal_info = !empty($cancelled_meals) ? " Today's ". implode(' & ', $cancelled_meals) ." cancelled." : "";
            
            $notification_msg = "🚨 Patient Discharged - Orders Cancelled: Patient '{$patient_name}' in {$suite_name} was discharged at " . $australiaTime->format('h:i A') . ".{$meal_info} Total {$cancelled_count} order item(s) for date(s): {$dates_str} have been automatically cancelled.";
            
            createNotification($this->tenantDb, 1, $this->selected_location_id, 'alert', $notification_msg);
            
            log_message('info', "NOTIFICATION SENT: Chef notified about $cancelled_count cancelled items for suite $suite_name due to patient discharge");
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 4: SEND EMAIL NOTIFICATION FOR DISCHARGE CANCELLATION
        // ═══════════════════════════════════════════════════════════════════
        if ($cancelled_count > 0) {
            try {
                $discharge_time = $australiaTime->format('d M Y h:i A');
                $unique_dates = array_unique($notification_dates);
                
                // Fetch cancelled item details for this suite from today's orders
                $cancelled_details_sql = "SELECT 
                        opo.id,
                        opo.category_id,
                        opo.cancel_reason,
                        opo.cancelled_at,
                        o.date as order_date,
                        fc.name as category_name,
                        md.name as menu_name,
                        mo.menu_option_name
                    FROM orders_to_patient_options opo
                    LEFT JOIN orders o ON o.order_id = opo.order_id
                    LEFT JOIN foodmenuconfig fc ON fc.id = opo.category_id AND fc.listtype = 'category'
                    LEFT JOIN menuDetails md ON md.id = opo.menu_id
                    LEFT JOIN menu_options mo ON mo.id = opo.option_id
                    WHERE opo.bed_id = ?
                    AND opo.is_cancelled = 1
                    AND opo.patient_name_snapshot = ?
                    ORDER BY o.date ASC, opo.category_id ASC";
                
                $detail_query = $this->tenantDb->query($cancelled_details_sql, [$suite_id, $patient_name]);
                $cancelled_items = ($detail_query && is_object($detail_query)) ? $detail_query->result_array() : [];
                
                // Build HTML email
                $email_subject = "Patient Discharged - Meals Cancelled | Suite {$suite_name} | {$patient_name}";
                
                $email_body = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; font-size: 14px; color: #333; }
                        .header { background-color: #dc3545; color: #fff; padding: 15px 20px; border-radius: 6px 6px 0 0; }
                        .header h2 { margin: 0; font-size: 18px; }
                        .content { padding: 20px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; }
                        .info-table { width: 100%; margin-bottom: 15px; }
                        .info-table td { padding: 6px 10px; }
                        .info-table .label { font-weight: bold; width: 160px; color: #555; }
                        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
                        table.items th { background-color: #f8f9fa; padding: 8px 12px; border: 1px solid #ddd; text-align: left; font-size: 13px; }
                        table.items td { padding: 8px 12px; border: 1px solid #ddd; font-size: 13px; }
                        table.items tr:nth-child(even) { background-color: #fafafa; }
                        .footer { margin-top: 20px; font-size: 12px; color: #888; border-top: 1px solid #eee; padding-top: 10px; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>&#9888; Patient Discharged - Meals Cancelled</h2>
                    </div>
                    <div class="content">
                        <table class="info-table">
                            <tr><td class="label">Patient Name:</td><td>' . htmlspecialchars($patient_name) . '</td></tr>
                            <tr><td class="label">Suite / Room:</td><td>' . htmlspecialchars($suite_name) . '</td></tr>
                            <tr><td class="label">Discharge Time:</td><td>' . $discharge_time . '</td></tr>
                            <tr><td class="label">Items Cancelled:</td><td>' . $cancelled_count . '</td></tr>
                            <tr><td class="label">Affected Date(s):</td><td>' . implode(', ', $unique_dates) . '</td></tr>
                        </table>';
                
                if (!empty($cancelled_items)) {
                    $email_body .= '
                        <h3 style="margin-top: 15px; font-size: 15px; color: #333;">Cancelled Meal Details</h3>
                        <table class="items">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Meal</th>
                                    <th>Menu Item</th>
                                    <th>Option</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>';
                    
                    foreach ($cancelled_items as $item) {
                        $reason_display = str_replace('_', ' ', $item['cancel_reason'] ?? '');
                        $reason_display = ucwords($reason_display);
                        
                        $email_body .= '
                                <tr>
                                    <td>' . date('d M Y', strtotime($item['order_date'])) . '</td>
                                    <td>' . htmlspecialchars($item['category_name'] ?? 'N/A') . '</td>
                                    <td>' . htmlspecialchars($item['menu_name'] ?? 'N/A') . '</td>
                                    <td>' . htmlspecialchars($item['menu_option_name'] ?? '-') . '</td>
                                    <td>' . htmlspecialchars($reason_display) . '</td>
                                </tr>';
                    }
                    
                    $email_body .= '
                            </tbody>
                        </table>';
                }
                
                $email_body .= '
                        <div class="footer">
                            <p>This is an automated notification from BizOrder system.</p>
                            <p>Generated at: ' . $discharge_time . '</p>
                        </div>
                    </div>
                </body>
                </html>';
                
                $this->sendEmail('kaushika@aaria.com.au', $email_subject, $email_body);
                
                log_message('info', "DISCHARGE EMAIL SENT: Email notification sent to kaushika@aaria.com.au for suite $suite_name discharge ($cancelled_count items cancelled)");
                
            } catch (Exception $e) {
                log_message('error', "DISCHARGE EMAIL FAILED: Could not send email for suite $suite_name discharge - " . $e->getMessage());
            }
        }
        
        return $cancelled_count;
    }
    
    /**
     * Soft cancel order items for a suite (sets is_cancelled = 1)
     * Used by cancelOrdersOnDischarge
     */
    private function softCancelOrderItemsForSuite($suite_id, $order_date, $category_ids, $patient_name, $suite_name, $cancel_reason, $order_id = null) {
        // Build the query to find items to cancel
        $this->tenantDb->select('opo.id, opo.order_id');
        $this->tenantDb->from('orders_to_patient_options opo');
        $this->tenantDb->join('orders o', 'o.order_id = opo.order_id', 'inner');
        $this->tenantDb->where('opo.bed_id', $suite_id);
        $this->tenantDb->where('opo.is_cancelled', 0);
        
        if ($order_id !== null) {
            $this->tenantDb->where('opo.order_id', $order_id);
        } else {
            $this->tenantDb->where('o.date', $order_date);
        }
        
        if ($category_ids !== null && !empty($category_ids)) {
            $this->tenantDb->where_in('opo.category_id', $category_ids);
        }
        
        $items_query = $this->tenantDb->get();
        $items_to_cancel = $items_query->result_array();
        
        if (empty($items_to_cancel)) {
            return 0;
        }
        
        // Get item IDs
        $item_ids = array_column($items_to_cancel, 'id');
        
        // Soft delete - update with cancellation info
        $cancel_data = array(
            'is_cancelled' => 1,
            'cancel_reason' => $cancel_reason,
            'cancelled_at' => australia_datetime(),
            'cancelled_by' => $this->session->userdata('user_id'),
            'patient_name_snapshot' => $patient_name,
            'suite_name_snapshot' => $suite_name
        );
        
        $this->tenantDb->where_in('id', $item_ids);
        $this->tenantDb->update('orders_to_patient_options', $cancel_data);
        
        $affected = $this->tenantDb->affected_rows();
        
        log_message('info', "SOFT CANCEL: Updated $affected items (IDs: " . implode(',', $item_ids) . ") with is_cancelled=1, reason=$cancel_reason");
        
        return $affected;
    }
    
    /**
     * Fix existing patients that might not have status set
     * This method can be called once to update legacy data
     */
    public function fixPatientStatus() {
        // Only allow admin users to run this
        if (!$this->ion_auth->is_admin()) {
            show_error('Access denied');
            return;
        }
        
        // Get all patients without status or with NULL status
        $conditions = array('status' => NULL);
        $patients_without_status = $this->common_model->fetchRecordsDynamically('people', '', $conditions);
        
        if (empty($patients_without_status)) {
            echo "No patients found without status. All patients already have status set.";
            return;
        }
        
        $updated_count = 0;
        foreach ($patients_without_status as $patient) {
            $update_data = array('status' => 1); // Set to active by default
            $result = $this->common_model->commonRecordUpdate('people', 'id', $patient['id'], $update_data);
            if ($result) {
                $updated_count++;
            }
        }
        
        echo "Updated status for $updated_count patients out of " . count($patients_without_status) . " patients without status.";
    }
    
    /**
     * Delete patient and free up their suite
     * Called via AJAX from patient list
     */
    public function deletePatient() {
        $this->load->helper('custom'); // Load custom helper for Australia timezone functions
        
        $patient_id = $this->input->post('id');
        
        // Validate input
        if (empty($patient_id)) {
            log_message('error', "PATIENT DELETE FAILED: No patient ID provided. User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Patient ID is required']);
            return;
        }
        
        // Get patient details to free up suite if needed
        $patient_conditions = array('id' => $patient_id);
        $patient_details = $this->common_model->fetchRecordsDynamically('people', '', $patient_conditions);
        
        if (empty($patient_details)) {
            log_message('error', "PATIENT DELETE FAILED: Patient ID={$patient_id} not found. User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Patient not found']);
            return;
        }
        
        $patient = $patient_details[0];
        
        log_message('info', "PATIENT DELETE: Attempting to delete Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", Suite Number=" . ($patient['suite_number'] ?: 'NONE') . ", Status=" . ($patient['status'] == 1 ? 'ACTIVE' : ($patient['status'] == 2 ? 'DISCHARGED' : 'UNKNOWN')) . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());
        
        // Delete the patient record
        $this->common_model->commonRecordDelete('people', $patient_id, 'id');
        
        // Check if deletion was successful
        if ($this->tenantDb->affected_rows() > 0) {
            // If patient was in a suite, make it vacant
            if (!empty($patient['suite_number'])) {
                $suite_update = array('is_vaccant' => 1);
                $this->common_model->commonRecordUpdate('suites', 'id', $patient['suite_number'], $suite_update);
                log_message('info', "SUITE STATUS UPDATE: Suite {$patient['suite_number']} marked as VACANT after patient deletion. Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . " at " . australia_datetime());
            }
            
            log_message('info', "PATIENT DELETE SUCCESS: Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", Suite Number=" . ($patient['suite_number'] ?: 'NONE') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", User ID=" . ($this->session->userdata('user_id') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . ", Timestamp=" . australia_datetime());
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Client deleted successfully'
            ]);
        } else {
            log_message('error', "PATIENT DELETE FAILED: Database deletion returned 0 affected rows for Patient ID={$patient_id}, Patient Name=" . ($patient['name'] ?: 'UNKNOWN') . ", User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . ", IP=" . $this->input->ip_address() . " at " . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete client']);
        }
    }

    /**
     * Cancel all future orders for a specific suite when patient is discharged
     * This is called when a patient's status is manually changed to 'discharged'
     * 
     * @param int $suite_id The suite/bed ID
     * @param string $today Today's date in Y-m-d format
     * @param string $patient_name Patient name for notification
     * @return int Number of orders cancelled
     */
    private function cancelFutureOrdersForPatientDischarge($suite_id, $today, $patient_name) {
        // Load notification helper
        $this->load->helper('notification');
        
        $cancelled_count = 0;
        
        // Get suite details for notification
        $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $suite_id]);
        $suite_name = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite $suite_id";
        
        // Find all active orders for this suite with date > today (future orders)
        $this->tenantDb->select('o.order_id, o.date, o.workflow_status, o.status, o.floor_id, o.is_floor_consolidated');
        $this->tenantDb->from('orders o');
        $this->tenantDb->where('o.date >', $today);
        $this->tenantDb->where('o.status !=', 0); // Not already cancelled
        $this->tenantDb->where_not_in('o.workflow_status', ['cancelled', 'cancelled_duplicate', 'deleted']);
        $this->tenantDb->group_start();
            // Suite-specific orders (legacy)
            $this->tenantDb->where('o.bed_id', $suite_id);
            $this->tenantDb->or_group_start();
                // Floor consolidated orders - check suite_order_details
                $this->tenantDb->where('o.is_floor_consolidated', 1);
                $this->tenantDb->where("EXISTS (SELECT 1 FROM suite_order_details sd WHERE sd.floor_order_id = o.order_id AND sd.suite_id = $suite_id)", NULL, FALSE);
            $this->tenantDb->group_end();
        $this->tenantDb->group_end();
        
        $future_orders_query = $this->tenantDb->get();
        $future_orders = $future_orders_query->result_array();
        
        if (empty($future_orders)) {
            log_message('info', "PATIENT DISCHARGE: No future orders found for suite $suite_name (ID: $suite_id)");
            return 0;
        }
        
        $notification_dates = [];
        
        foreach ($future_orders as $order) {
            $order_id = $order['order_id'];
            $order_date = $order['date'];
            
            // Check if this is a floor-consolidated order
            $is_floor_order = isset($order['is_floor_consolidated']) && $order['is_floor_consolidated'] == 1;
            
            if ($is_floor_order) {
                // For floor-consolidated orders:
                // 1. Delete menu items for this suite from orders_to_patient_options
                // 2. Delete suite_order_details for this suite
                // 3. Only cancel entire floor order if no other suites have items
                
                // Delete from orders_to_patient_options for this suite
                $this->tenantDb->where('order_id', $order_id);
                $this->tenantDb->where('bed_id', $suite_id);
                $this->tenantDb->delete('orders_to_patient_options');
                $deleted_items = $this->tenantDb->affected_rows();
                
                // Delete from suite_order_details for this suite
                $this->tenantDb->where('floor_order_id', $order_id);
                $this->tenantDb->where('suite_id', $suite_id);
                $this->tenantDb->delete('suite_order_details');
                
                log_message('info', "PATIENT DISCHARGE ORDER CANCEL: Removed $deleted_items menu items for suite $suite_name from floor order $order_id (date: $order_date)");
                
                // Check if any other suites have items in this floor order
                $remaining_items = $this->tenantDb->where('order_id', $order_id)
                    ->count_all_results('orders_to_patient_options');
                    
                if ($remaining_items == 0) {
                    // No items left, cancel the entire floor order
                    $cancel_data = array(
                        'status' => 0,
                        'workflow_status' => 'cancelled',
                        'date_modified' => australia_datetime()
                    );
                    $this->tenantDb->where('order_id', $order_id);
                    $this->tenantDb->update('orders', $cancel_data);
                    
                    log_message('info', "PATIENT DISCHARGE ORDER CANCEL: Entire floor order $order_id cancelled (no remaining items)");
                }
                
                $cancelled_count++;
                $notification_dates[] = date('d-m-Y', strtotime($order_date));
                
            } else {
                // For suite-specific orders, cancel the entire order
                $cancel_data = array(
                    'status' => 0,
                    'workflow_status' => 'cancelled',
                    'date_modified' => australia_datetime()
                );
                
                $this->tenantDb->where('order_id', $order_id);
                $update_result = $this->tenantDb->update('orders', $cancel_data);
                
                if ($update_result) {
                    $cancelled_count++;
                    $notification_dates[] = date('d-m-Y', strtotime($order_date));
                    
                    log_message('info', "PATIENT DISCHARGE ORDER CANCEL: Cancelled order ID $order_id for suite $suite_name (date: $order_date)");
                }
            }
        }
        
        // Send notification to Chef about cancelled orders
        if ($cancelled_count > 0 && function_exists('createNotification')) {
            $unique_dates = array_unique($notification_dates);
            $dates_str = implode(', ', $unique_dates);
            
            $notification_msg = "Patient Discharged - Orders Cancelled: Patient '{$patient_name}' in {$suite_name} was discharged. {$cancelled_count} future order(s) for date(s): {$dates_str} have been automatically cancelled.";
            
            // Send notification (system_id=1 typically means admin/chef notification)
            createNotification($this->tenantDb, 1, $this->selected_location_id, 'alert', $notification_msg);
            
            log_message('info', "NOTIFICATION SENT: Chef notified about $cancelled_count cancelled orders for suite $suite_name due to patient discharge");
        }
        
        return $cancelled_count;
    }

    /**
     * Transfer all future/active orders from one suite to another
     * This is called when a patient is moved between suites to ensure their orders follow them
     * 
     * @param int $source_suite_id The suite ID the patient is moving FROM
     * @param int $destination_suite_id The suite ID the patient is moving TO
     * @param string $patient_name Patient name for logging
     * @return int Number of orders transferred
     */
    private function transferSuiteOrders($source_suite_id, $destination_suite_id, $patient_name = 'Unknown') {
        $this->load->helper('custom');
        $today = date('Y-m-d');
        $orders_transferred = 0;
        
        // Get source and destination suite names for logging
        $source_suite = $this->tenantDb->where('id', $source_suite_id)->get('suites')->row_array();
        $dest_suite = $this->tenantDb->where('id', $destination_suite_id)->get('suites')->row_array();
        $source_name = $source_suite ? $source_suite['bed_no'] : "Suite {$source_suite_id}";
        $dest_name = $dest_suite ? $dest_suite['bed_no'] : "Suite {$destination_suite_id}";
        
        log_message('info', "ORDER TRANSFER (Patient Edit): Starting order transfer from {$source_name} (ID:{$source_suite_id}) to {$dest_name} (ID:{$destination_suite_id}) for patient '{$patient_name}'. User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . " at " . australia_datetime());
        
        // Find all orders for the source suite that are:
        // - Today or future dates (date >= today)
        // - Not delivered (is_delivered != 1)
        // - Not cancelled (status != 0)
        $this->tenantDb->where('bed_id', $source_suite_id);
        $this->tenantDb->where('date >=', $today);
        $this->tenantDb->where('status !=', 0); // Not cancelled
        $this->tenantDb->where('is_delivered !=', 1); // Not delivered
        $orders_to_transfer = $this->tenantDb->get('orders')->result_array();
        
        if (empty($orders_to_transfer)) {
            log_message('info', "ORDER TRANSFER: No active/future orders found for {$source_name} to transfer.");
            return 0;
        }
        
        log_message('info', "ORDER TRANSFER: Found " . count($orders_to_transfer) . " order(s) to transfer from {$source_name} to {$dest_name}");
        
        foreach ($orders_to_transfer as $order) {
            $order_id = $order['order_id'];
            $order_date = $order['date'];
            
            try {
                // Update the main order record - change bed_id
                $this->tenantDb->where('order_id', $order_id);
                $this->tenantDb->update('orders', ['bed_id' => $destination_suite_id]);
                
                // Update orders_to_patient_options - change bed_id for all items
                $this->tenantDb->where('order_id', $order_id);
                $this->tenantDb->where('bed_id', $source_suite_id);
                $this->tenantDb->update('orders_to_patient_options', ['bed_id' => $destination_suite_id]);
                
                // Update orders_to_comments - change bed_id
                $this->tenantDb->where('order_id', $order_id);
                $this->tenantDb->where('bed_id', $source_suite_id);
                $this->tenantDb->update('orders_to_comments', ['bed_id' => $destination_suite_id]);
                
                // Update suite_order_details - change suite_id if table exists
                if ($this->tenantDb->table_exists('suite_order_details')) {
                    $this->tenantDb->where('suite_id', $source_suite_id);
                    if (!empty($order['floor_order_id'])) {
                        $this->tenantDb->where('floor_order_id', $order['floor_order_id']);
                    }
                    $this->tenantDb->update('suite_order_details', ['suite_id' => $destination_suite_id]);
                }
                
                // Update delivery status tables
                if ($this->tenantDb->table_exists('orders_to_deliverystatus')) {
                    $this->tenantDb->where('order_id', $order_id);
                    $this->tenantDb->where('bed_id', $source_suite_id);
                    $this->tenantDb->update('orders_to_deliverystatus', ['bed_id' => $destination_suite_id]);
                }
                
                if ($this->tenantDb->table_exists('orders_to_packagestatus')) {
                    $this->tenantDb->where('order_id', $order_id);
                    $this->tenantDb->where('bed_id', $source_suite_id);
                    $this->tenantDb->update('orders_to_packagestatus', ['bed_id' => $destination_suite_id]);
                }
                
                $orders_transferred++;
                log_message('info', "ORDER TRANSFER: Successfully transferred order ID={$order_id} (date={$order_date}) from {$source_name} to {$dest_name}");
                
            } catch (Exception $e) {
                log_message('error', "ORDER TRANSFER ERROR: Failed to transfer order ID={$order_id}. Error: " . $e->getMessage());
            }
        }
        
        // Create notification about the transfer
        if ($orders_transferred > 0) {
            $this->load->helper('notification');
            $msg = "Suite Transfer: {$orders_transferred} order(s) transferred from {$source_name} to {$dest_name} for patient {$patient_name}";
            createNotification($this->tenantDb, 1, $this->selected_location_id, 'info', $msg);
        }
        
        log_message('info', "ORDER TRANSFER COMPLETE: Transferred {$orders_transferred} order(s) from {$source_name} to {$dest_name} for patient '{$patient_name}'");
        
        return $orders_transferred;
    }

    
}
    
    ?>