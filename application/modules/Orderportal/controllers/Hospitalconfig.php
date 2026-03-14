<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Hospitalconfig extends MY_Controller
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
    
    function List(){
        $show_deleted = $this->input->get('show_deleted');
        
        $conditions = array('location_id' => $this->selected_location_id, 'is_deleted' => 0,'listtype'=>'floor');
        $floorListData = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
        $data['floorLists'] = $floorListData;
        
        if ($show_deleted == '1') {
            // Show deleted suites
            $data['bedLists'] = $this->menu_model->fetchAllBedDetails(true); // true for deleted
            $data['show_deleted'] = true;
        } else {
            // Show active suites (default)
            $data['bedLists'] = $this->menu_model->fetchAllBedDetails();
            $data['show_deleted'] = false;
        }
        
        // ✅ FIX: Get user role to hide delete button for nurses
        $userRole = $this->ion_auth->get_users_groups()->row()->id ?? 0;
        $data['userRole'] = $userRole;
        
        
    	$this->load->view('general/landingPageHeader');
        $this->load->view('Hospitalconfig/list',$data);
        $this->load->view('general/landingPageFooter');    
    }
    
    function ClientList(){
        $conditions = array('location_id' => $this->selected_location_id, 'is_deleted' => 0);
        $data['bedLists']   = $this->common_model->fetchRecordsDynamically('suites','',$conditions);
        
        $conditions['listtype'] = 'floor';
        $floorListData = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
        $data['floorLists'] = $floorListData;
        
    	$this->load->view('general/landingPageHeader');
        $this->load->view('Hospitalconfig/list',$data);
        $this->load->view('general/landingPageFooter');    
    }
    

    
    // add suite
    function addBed($bedId = null){
        
        $conditions['listtype'] = 'floor';
        $floorListData = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
        $data['floorLists'] = $floorListData;
        
        // If bedId is provided, fetch suite details for editing
        if ($bedId) {
            $conditionsP = array('status' => 1, 'id' => $bedId);
            $bedDetails = $this->common_model->fetchRecordsDynamically('suites', '', $conditionsP);
            $data['bedDetails'] = (isset($bedDetails[0]) && !empty($bedDetails) ? reset($bedDetails) : array());
        }
        
      	$this->load->view('general/landingPageHeader');
        $this->load->view('Hospitalconfig/addBed',$data);
        $this->load->view('general/landingPageFooter');     
    }
    
    public function submitSuite(){
   		if (!$this->ion_auth->logged_in()) {
			redirect('auth/login');
		}else{
		   if ($this->input->post()) {
		       
		       // Server-side validation
		       $this->load->library('form_validation');
		       
		       $this->form_validation->set_rules('bed_no', 'Suite Number', 'required|trim');
		       $this->form_validation->set_rules('floor', 'Floor', 'required');
		       $this->form_validation->set_rules('suite_pin', 'Suite PIN', 'required|trim|min_length[4]|max_length[6]|numeric');
		       
		       if ($this->form_validation->run() == FALSE) {
		           $this->session->set_flashdata('error_msg', validation_errors());
		           redirect('Orderportal/Hospitalconfig/addBed');
		           return;
		       }
		       
		       $bed_no = trim($this->input->post('bed_no'));
		       $floor = $this->input->post('floor');
		       $bed_id = $this->input->post('bedId');
		       
		       // Check for duplicate suite number (excluding current record if updating and deleted suites)
		       // If updating, exclude current record from duplicate check
		       if (!empty($bed_id)) {
		           $existing_suites = $this->tenantDb->where('bed_no', $bed_no)
		                                           ->where('location_id', $this->selected_location_id)
		                                           ->where('id !=', $bed_id)
		                                           ->where('(is_deleted IS NULL OR is_deleted = 0)')
		                                           ->get('suites')
		                                           ->result_array();
		       } else {
		           // For new suites, check duplicates but exclude deleted ones
		           $existing_suites = $this->tenantDb->where('bed_no', $bed_no)
		                                           ->where('location_id', $this->selected_location_id)
		                                           ->where('(is_deleted IS NULL OR is_deleted = 0)')
		                                           ->get('suites')
		                                           ->result_array();
		       }
		       
		       if (!empty($existing_suites)) {
		           $this->session->set_flashdata('error_msg', 'Suite number "' . htmlspecialchars($bed_no) . '" already exists. Please choose a different suite number.');
		           redirect('Orderportal/Hospitalconfig/addBed');
		           return;
		       }

		       $menuData=array(
		           'bed_no' => $bed_no,    
    			   'floor' => $floor,
    			   'suite_pin' => trim($this->input->post('suite_pin')),
    			   'notes' => $this->input->post('notes'),
    			   'location_id' => $this->selected_location_id,
    			   'status' => 1, // Default status
    			   'is_vaccant' => 1 // Default to vacant
			   );
		   
		       $this->load->helper('custom');
		       $userId = $this->ion_auth->user()->row()->id;
		       $userEmail = $this->ion_auth->user()->row()->email;
		       $userIP = $this->input->ip_address();
		       
		       if(empty($bed_id)){
		           $menuData['date_added'] = australia_datetime();
		           $actionid = $this->common_model->commonRecordCreate('suites',$menuData);
		           
		           if($actionid){
		               log_message('info', "SUITE CREATE: Suite created successfully. Suite Number={$bed_no}, Suite ID={$actionid}, Floor={$floor}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
		           } else {
		               log_message('error', "SUITE CREATE FAILED: Failed to create suite. Suite Number={$bed_no}, Floor={$floor}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
		           }
		       }else{
		           $menuData['date_modified'] = australia_datetime();
		           $oldSuite = $this->tenantDb->where('id', $bed_id)->get('suites')->row_array();
		           $oldSuiteNumber = $oldSuite ? $oldSuite['bed_no'] : 'Unknown';
		           $oldFloor = $oldSuite ? $oldSuite['floor'] : 'Unknown';
		           
		           $this->common_model->commonRecordUpdate('suites','id',$bed_id,$menuData);
		           $affected_rows = $this->tenantDb->affected_rows();
		           $actionid = $bed_id;
		           
		           if($affected_rows > 0){
		               log_message('info', "SUITE UPDATE: Suite updated successfully. Suite ID={$bed_id}, Old Suite Number={$oldSuiteNumber}, New Suite Number={$bed_no}, Old Floor={$oldFloor}, New Floor={$floor}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
		           } else {
		               log_message('warning', "SUITE UPDATE: No rows affected. Suite ID={$bed_id}, Suite Number={$bed_no}, Floor={$floor}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
		           }
		       }
		   
			   if($actionid){
				   $this->session->set_flashdata('sucess_msg', 'Suite has been successfully ' . (empty($bed_id) ? 'added' : 'updated'));
			   }else{
				   $this->session->set_flashdata('error_msg', 'Unable to ' . (empty($bed_id) ? 'add' : 'update') . ' the suite');
			   }
		       redirect('Orderportal/Hospitalconfig/List');
		   }else{
		       redirect('Orderportal/Hospitalconfig/addBed');
		   }
      }
	}
	
	/**
	 * AJAX method to check if suite number already exists
	 */
	public function checkDuplicateSuite() {
	    if (!$this->ion_auth->logged_in()) {
	        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
	        return;
	    }
	    
	    $bed_no = trim($this->input->post('bed_no'));
	    $bed_id = $this->input->post('bed_id'); // For edit mode
	    
	    if (empty($bed_no)) {
	        echo json_encode(['status' => 'error', 'message' => 'Suite number is required']);
	        return;
	    }
	    
	    // Check for duplicate suite number (exclude deleted suites)
	    if (!empty($bed_id)) {
	        // Updating existing suite - exclude current record and deleted suites
	        $existing_suites = $this->tenantDb->where('bed_no', $bed_no)
	                                        ->where('location_id', $this->selected_location_id)
	                                        ->where('id !=', $bed_id)
	                                        ->where('(is_deleted IS NULL OR is_deleted = 0)')
	                                        ->get('suites')
	                                        ->result_array();
	    } else {
	        // Adding new suite - exclude deleted suites
	        $existing_suites = $this->tenantDb->where('bed_no', $bed_no)
	                                        ->where('location_id', $this->selected_location_id)
	                                        ->where('(is_deleted IS NULL OR is_deleted = 0)')
	                                        ->get('suites')
	                                        ->result_array();
	    }
	    
	    if (!empty($existing_suites)) {
	        echo json_encode([
	            'status' => 'exists', 
	            'message' => 'Suite number "' . htmlspecialchars($bed_no) . '" already exists'
	        ]);
	    } else {
	        echo json_encode([
	            'status' => 'available', 
	            'message' => 'Suite number is available'
	        ]);
	    }
	}
	
	public function viewSuite($suiteId){
	    
	    // Check for active patients in the suite (not discharged and no past discharge date)
	    $today = date('Y-m-d');
	    $conditionsP = array(
	        'status' => 1, 
	        'suite_number' => $suiteId
	    );
	    
	    // Get all patients in this suite
	    $clientDetails = $this->common_model->fetchRecordsDynamically('people', '', $conditionsP);
	    
	    // Filter out patients with discharge date that has passed
	    $activeClients = array();
	    $discharged_patients = array();
	    
	    if(isset($clientDetails) && !empty($clientDetails)){
	        foreach($clientDetails as $client) {
	            $discharge_date = $client['date_of_discharge'];
	            $onboard_date = $client['date_onboarded'];
	            
	            // ✅ FIX: Handle NULL discharge dates properly
	            // Keep patients active if:
	            // 1. No discharge date (NULL, empty string, or 'NULL' string)
	            // 2. Discharge date is in the future
	            // 3. Discharge date is today (same-day patients stay active for the day)
	            if(empty($discharge_date) || $discharge_date === NULL || $discharge_date === 'NULL' || $discharge_date === '0000-00-00' || $discharge_date >= $today) {
	                $activeClients[] = $client;
	            } else {
	                // Patient has discharge date in the past, mark as discharged
	                $discharged_patients[] = $client;
	            }
	        }
	    }
	    
	    // If there are patients with past discharge dates, update their status
	    if(!empty($discharged_patients)){
	        foreach($discharged_patients as $discharged_patient) {
	            $patient_update = array(
	                'status' => 2, // Mark as discharged
	                'date_modified' => date('Y-m-d H:i:s')
	            );
	            $this->common_model->commonRecordUpdate('people', 'id', $discharged_patient['id'], $patient_update);
	        }
	    }
	    
	    // Update suite vacancy status based on active patients
	    $suite_update = array(
	        'is_vaccant' => empty($activeClients) ? 1 : 0
	    );
	    $this->common_model->commonRecordUpdate('suites', 'id', $suiteId, $suite_update);
	    
	    if(!empty($activeClients)){
	        $this->onboardingForm($activeClients[0]['id']);   
	    }else{
	        // Suite is now vacant, redirect to onboarding form for new patient
	        redirect('Orderportal/Patient/onboardingForm/' . $suiteId . '/suite');
	    }
        
	}
	
	/**
	 * Sync suite vacancy status with actual patient occupancy
	 * This method can be called to fix any inconsistencies between suite status and patient data
	 */
	public function syncSuiteStatus() {
	    $today = date('Y-m-d');
	    $synced_suites = 0;
	    
	    // Get all suites
	    $conditions = array('status' => 1, 'is_deleted' => 0);
	    $all_suites = $this->common_model->fetchRecordsDynamically('suites', '', $conditions);
	    
	    if (!empty($all_suites)) {
	        foreach ($all_suites as $suite) {
	            $suite_id = $suite['id'];
	            
	            // Check for active patients in this suite
	            $patient_conditions = array(
	                'status' => 1,
	                'suite_number' => $suite_id
	            );
	            $patients = $this->common_model->fetchRecordsDynamically('people', '', $patient_conditions);
	            
	            // Filter active patients (no discharge date, future discharge date, or same-day discharge)
	            $active_patients = array();
	            if (!empty($patients)) {
	                foreach ($patients as $patient) {
	                    $discharge_date = $patient['date_of_discharge'];
	                    // ✅ FIX: Handle NULL discharge dates properly
	                    // Keep patients active if:
	                    // 1. No discharge date (NULL, empty string, or 'NULL' string)
	                    // 2. Discharge date is today or in the future
	                    if (empty($discharge_date) || $discharge_date === NULL || $discharge_date === 'NULL' || $discharge_date === '0000-00-00' || $discharge_date >= $today) {
	                        $active_patients[] = $patient;
	                    }
	                }
	            }
	            
	            // Determine correct vacancy status
	            $should_be_vacant = empty($active_patients) ? 1 : 0;
	            
	            // Update suite if status doesn't match
	            if ($suite['is_vaccant'] != $should_be_vacant) {
	                $suite_update = array('is_vaccant' => $should_be_vacant);
	                $this->common_model->commonRecordUpdate('suites', 'id', $suite_id, $suite_update);
	                $synced_suites++;
	                
	                $status_text = $should_be_vacant ? 'vacant' : 'occupied';
	                echo "Updated Suite {$suite['bed_no']} to {$status_text}\n";
	            }
	        }
	    }
	    
	    echo "Sync complete: {$synced_suites} suites updated\n";
	}

	 public function dischargeSuite() {
        // Restrict to CLI only
        if (!is_cli()) {
            show_error('This method is CLI-only');
        }

        // Load notification helper for sending chef notifications
        $this->load->helper('notification');
        $this->load->helper('custom');
       
        $today = date('Y-m-d');

        // Conditions to fetch people with discharge_date = today and status = 1
        $conditions = array('DATE(date_of_discharge)' => $today, 'status' => 1);
        $people = $this->common_model->fetchRecordsDynamically('people', '', $conditions);

        // Check if records exist
        if (!isset($people) || empty($people)) {
            $message = "No patients found with discharge date $today";
            log_message('info', $message);
            echo "$message\n";
            return;
        }

        // Load AuditTrail model
        try {
            $this->load->model('AuditTrail_model');
        } catch (Exception $e) {
            log_message('error', 'dischargeSuite: Could not load AuditTrail_model: ' . $e->getMessage());
        }

        // Process each person
        $updated_patients = 0;
        $updated_suites = 0;
        $cancelled_orders = 0;
        foreach ($people as $person) {
            if (isset($person['suite_number']) && !empty($person['suite_number'])) {
                $suite_number = $person['suite_number'];
                $person_id = $person['id'];
                $patient_name = isset($person['name']) ? $person['name'] : 'Unknown';

                // Update patient status to discharged (2) WITH time_discharged
                $patient_update = array(
                    'status' => 2, 
                    'date_modified' => date('Y-m-d H:i:s'),
                    'time_discharged' => date('Y-m-d H:i:s')
                );
                $patient_result = $this->common_model->commonRecordUpdate('people', 'id', $person_id, $patient_update);

                if ($patient_result) {
                    $updated_patients++;
                    $message = "Updated patient ID $person_id status to discharged";
                    log_message('info', $message);
                    echo "$message\n";

                    // Update suite to vacant (is_vaccant = 1)
                    $suite_update = array('is_vaccant' => 1);
                    $suite_result = $this->common_model->commonRecordUpdate('suites', 'id', $suite_number, $suite_update);

                    if ($suite_result && $this->tenantDb->affected_rows() > 0) {
                        $updated_suites++;
                        $message = "Updated suite $suite_number to vacant";
                        log_message('info', $message);
                        echo "$message\n";
                    } else {
                        $message = "Failed to update suite $suite_number to vacant";
                        log_message('error', $message);
                        echo "$message\n";
                    }
                    
                    // ═══════════════════════════════════════════════════════════════════════
                    // CANCEL FUTURE ORDERS: Cancel all orders for this suite with date > today
                    // ═══════════════════════════════════════════════════════════════════════
                    $cancelled_count = $this->cancelFutureOrdersForSuite($suite_number, $today, $patient_name);
                    $cancelled_orders += $cancelled_count;
                    
                    // ═══════════════════════════════════════════════════════════════════════
                    // LOG TO AUDIT TRAIL
                    // ═══════════════════════════════════════════════════════════════════════
                    if (isset($this->AuditTrail_model)) {
                        try {
                            $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $suite_number]);
                            $suite_name = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite $suite_number";
                            
                            $floor_details = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], ['id' => $person['floor_number'], 'listtype' => 'floor']);
                            $floor_name = !empty($floor_details) ? $floor_details[0]['name'] : "Floor " . ($person['floor_number'] ?? 'N/A');
                            
                            $this->AuditTrail_model->logDischarge(
                                $person_id,
                                $patient_name,
                                $suite_number,
                                $suite_name,
                                $person['floor_number'] ?? null,
                                $floor_name,
                                $cancelled_count,
                                'CLI batch discharge on ' . $today
                            );
                        } catch (Exception $e) {
                            log_message('error', 'dischargeSuite: Audit trail log failed for patient ' . $person_id . ': ' . $e->getMessage());
                        }
                    }
                    
                } else {
                    $message = "Failed to update patient ID $person_id status";
                    log_message('error', $message);
                    echo "$message\n";
                }
            } else {
                $message = "Invalid or missing suite_number for person ID: " . (isset($person['id']) ? $person['id'] : 'unknown');
                log_message('error', $message);
                echo "$message\n";
            }
        }

        // Summary
        $summary = "DischargeSuite processed: $updated_patients patients discharged, $updated_suites suites made vacant, $cancelled_orders future orders cancelled for discharge date $today";
        log_message('info', $summary);
        echo "$summary\n";
    }
    
    /**
     * Cancel orders for a specific suite when patient is discharged
     * 
     * CANCELLATION RULES:
     * - All future orders (date > today): Cancel all meals
     * - Same-day orders with discharge after 11am: Cancel LUNCH + DINNER
     * - Same-day orders with discharge after 2pm: Cancel DINNER only
     * 
     * Uses SOFT DELETE (is_cancelled = 1) for reporting and audit trail
     * 
     * @param int $suite_id The suite/bed ID
     * @param string $today Today's date in Y-m-d format
     * @param string $patient_name Patient name for notification
     * @return int Number of order items cancelled
     */
    private function cancelFutureOrdersForSuite($suite_id, $today, $patient_name) {
        $cancelled_count = 0;
        
        // Get suite details for notification
        $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $suite_id]);
        $suite_name = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite $suite_id";
        
        // ═══════════════════════════════════════════════════════════════════
        // TIME-BASED CANCELLATION LOGIC (Points 5 & 6)
        // Get current Australia/Sydney time for same-day cancellation rules
        // ═══════════════════════════════════════════════════════════════════
        $australiaTime = new DateTime('now', new DateTimeZone('Australia/Sydney'));
        $currentHour = (int) $australiaTime->format('H');
        $currentMinute = (int) $australiaTime->format('i');
        
        // Category IDs from foodmenuconfig table
        // BREAKFAST = 3, LUNCH = 5, DINNER = 7
        $BREAKFAST_CATEGORY_ID = 3;
        $LUNCH_CATEGORY_ID = 5;
        $DINNER_CATEGORY_ID = 7;
        
        // Determine which categories to cancel for TODAY based on discharge time
        $categoriesToCancelToday = [];
        $sameDayCancelReason = '';
        
        if ($currentHour < 11) {
            // Before 11am - cancel LUNCH + DINNER for today (Point 5)
            $categoriesToCancelToday = [$LUNCH_CATEGORY_ID, $DINNER_CATEGORY_ID];
            $sameDayCancelReason = 'discharged_before_11am';
            log_message('info', "DISCHARGE TIME CHECK: Before 11am ($currentHour:$currentMinute) - Will cancel LUNCH + DINNER for today");
        } elseif ($currentHour < 14) {
            // Before 2pm (but after 11am) - cancel DINNER only for today (Point 6)
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
        // STEP 1: CANCEL SAME-DAY ORDERS (Based on time rules - Points 5 & 6)
        // ═══════════════════════════════════════════════════════════════════
        if (!empty($categoriesToCancelToday)) {
            $todayCancelledCount = $this->softCancelOrderItems(
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
                
                $message = "DISCHARGE SAME-DAY CANCEL: Cancelled $todayCancelledCount item(s) for suite $suite_name today (". implode(' & ', $cancelled_meals) .")";
                log_message('info', $message);
                echo "$message\n";
            }
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 2: CANCEL ALL FUTURE ORDERS (date > today) - Point 1
        // ═══════════════════════════════════════════════════════════════════
        
        // Find all active orders for this suite with date > today
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
        
        if (!empty($future_orders)) {
            foreach ($future_orders as $order) {
                $order_id = $order['order_id'];
                $order_date = $order['date'];
                
                // Soft cancel ALL categories for future orders (no category filter)
                $futureCancelledCount = $this->softCancelOrderItems(
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
                    
                    $message = "DISCHARGE FUTURE CANCEL: Soft-cancelled $futureCancelledCount item(s) for suite $suite_name, order $order_id (date: $order_date)";
                    log_message('info', $message);
                    echo "$message\n";
                }
                
                // Check if any ACTIVE items remain in this order
                $remaining_items = $this->tenantDb
                    ->where('order_id', $order_id)
                    ->where('is_cancelled', 0)
                    ->count_all_results('orders_to_patient_options');
                    
                if ($remaining_items == 0) {
                    // No active items left, mark entire order as cancelled
                    $cancel_data = array(
                        'status' => 0,
                        'workflow_status' => 'cancelled',
                        'date_modified' => date('Y-m-d H:i:s')
                    );
                    $this->tenantDb->where('order_id', $order_id);
                    $this->tenantDb->update('orders', $cancel_data);
                    
                    log_message('info', "DISCHARGE ORDER CANCEL: Entire order $order_id marked as cancelled (no remaining active items)");
                }
            }
        } else {
            $message = "No future orders found for suite $suite_name (ID: $suite_id)";
            log_message('info', $message);
            echo "$message\n";
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // STEP 3: SEND NOTIFICATION TO CHEF (Point 2)
        // ═══════════════════════════════════════════════════════════════════
        if ($cancelled_count > 0 && function_exists('createNotification')) {
            $unique_dates = array_unique($notification_dates);
            $dates_str = implode(', ', $unique_dates);
            
            $meal_info = !empty($cancelled_meals) ? " Today's ". implode(' & ', $cancelled_meals) ." cancelled." : "";
            
            $notification_msg = "🚨 Patient Discharged - Orders Cancelled: Patient '{$patient_name}' in {$suite_name} was discharged at " . $australiaTime->format('h:i A') . ".{$meal_info} Total {$cancelled_count} order item(s) for date(s): {$dates_str} have been automatically cancelled.";
            
            // Send notification (system_id=1 typically means admin/chef notification)
            createNotification($this->tenantDb, 1, $this->selected_location_id, 'alert', $notification_msg);
            
            $message = "NOTIFICATION SENT: Chef notified about $cancelled_count cancelled items for suite $suite_name";
            log_message('info', $message);
            echo "$message\n";
        }
        
        return $cancelled_count;
    }
    
    /**
     * Soft cancel order items for a suite (sets is_cancelled = 1)
     * 
     * @param int $suite_id Suite/bed ID
     * @param string $order_date Order date (Y-m-d)
     * @param array|null $category_ids Array of category IDs to cancel, or null for all
     * @param string $patient_name Patient name for snapshot
     * @param string $suite_name Suite name for snapshot
     * @param string $cancel_reason Reason for cancellation
     * @param int|null $order_id Specific order ID (optional)
     * @return int Number of items cancelled
     */
    private function softCancelOrderItems($suite_id, $order_date, $category_ids, $patient_name, $suite_name, $cancel_reason, $order_id = null) {
        // Build the update query
        $this->tenantDb->select('opo.id, opo.order_id');
        $this->tenantDb->from('orders_to_patient_options opo');
        $this->tenantDb->join('orders o', 'o.order_id = opo.order_id', 'inner');
        $this->tenantDb->where('opo.bed_id', $suite_id);
        $this->tenantDb->where('opo.is_cancelled', 0); // Only active items
        
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
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancelled_by' => null, // System/automatic cancellation
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
     * Process all patients with discharge dates that have passed
     * Can be called via web interface or CLI
     */
    public function processDischarges() {
        $this->load->helper('custom');
        $today = date('Y-m-d');
        
        // Get all active patients with discharge dates that have passed
        $conditions = array('status' => 1);
        $all_patients = $this->common_model->fetchRecordsDynamically('people', '', $conditions);
        
        $processed_patients = 0;
        $processed_suites = 0;
        $total_cancelled_orders = 0;
        
        // Load AuditTrail model
        try {
            $this->load->model('AuditTrail_model');
        } catch (Exception $e) {
            log_message('error', 'processDischarges: Could not load AuditTrail_model: ' . $e->getMessage());
        }
        
        if (!empty($all_patients)) {
            foreach ($all_patients as $patient) {
                $discharge_date = $patient['date_of_discharge'];
                
                // Check if patient has a discharge date that has passed
                if (!empty($discharge_date) && $discharge_date <= $today) {
                    $patient_id = $patient['id'];
                    $suite_number = $patient['suite_number'];
                    $patient_name = $patient['name'] ?: 'Unknown';
                    
                    // Update patient status to discharged WITH time_discharged
                    $patient_update = array(
                        'status' => 2, 
                        'date_modified' => date('Y-m-d H:i:s')
                    );
                    
                    // Set time_discharged if not already set
                    if (empty($patient['time_discharged'])) {
                        $patient_update['time_discharged'] = $discharge_date . ' 23:59:00';
                    }
                    
                    $patient_result = $this->common_model->commonRecordUpdate('people', 'id', $patient_id, $patient_update);
                    
                    if ($patient_result) {
                        $processed_patients++;
                        $cancelled_count = 0;
                        
                        // Update suite to vacant if patient was in a suite
                        if (!empty($suite_number)) {
                            $suite_update = array('is_vaccant' => 1);
                            $suite_result = $this->common_model->commonRecordUpdate('suites', 'id', $suite_number, $suite_update);
                            
                            if ($suite_result) {
                                $processed_suites++;
                            }
                            
                            // Cancel future orders for this suite
                            $suite_details = $this->common_model->fetchRecordsDynamically('suites', ['bed_no'], ['id' => $suite_number]);
                            $suite_name = !empty($suite_details) ? $suite_details[0]['bed_no'] : "Suite $suite_number";
                            
                            // Find and cancel all active order items for this suite (today + future)
                            $this->tenantDb->select('opo.id');
                            $this->tenantDb->from('orders_to_patient_options opo');
                            $this->tenantDb->join('orders o', 'o.order_id = opo.order_id', 'inner');
                            $this->tenantDb->where('opo.bed_id', $suite_number);
                            $this->tenantDb->where('opo.is_cancelled', 0);
                            $this->tenantDb->where('o.date >=', $today);
                            $items_query = $this->tenantDb->get();
                            
                            if ($items_query && $items_query->num_rows() > 0) {
                                $item_ids = array_column($items_query->result_array(), 'id');
                                
                                $cancel_data = array(
                                    'is_cancelled' => 1,
                                    'cancel_reason' => 'patient_discharged',
                                    'cancelled_at' => date('Y-m-d H:i:s'),
                                    'cancelled_by' => null,
                                    'patient_name_snapshot' => $patient_name,
                                    'suite_name_snapshot' => $suite_name
                                );
                                
                                $this->tenantDb->where_in('id', $item_ids);
                                $this->tenantDb->update('orders_to_patient_options', $cancel_data);
                                $cancelled_count = $this->tenantDb->affected_rows();
                                $total_cancelled_orders += $cancelled_count;
                            }
                            
                            // Log to audit trail
                            if (isset($this->AuditTrail_model)) {
                                try {
                                    $floor_details = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], ['id' => $patient['floor_number'], 'listtype' => 'floor']);
                                    $floor_name = !empty($floor_details) ? $floor_details[0]['name'] : "Floor " . ($patient['floor_number'] ?? 'N/A');
                                    
                                    $this->AuditTrail_model->logDischarge(
                                        $patient_id,
                                        $patient_name,
                                        $suite_number,
                                        $suite_name,
                                        $patient['floor_number'] ?? null,
                                        $floor_name,
                                        $cancelled_count,
                                        'Processed discharge: date=' . $discharge_date . ', orders cancelled=' . $cancelled_count
                                    );
                                } catch (Exception $e) {
                                    log_message('error', 'processDischarges: Audit log failed for patient ' . $patient_id . ': ' . $e->getMessage());
                                }
                            }
                        }
                    }
                }
            }
        }
        
        $message = "Processed discharges: $processed_patients patients discharged, $processed_suites suites made vacant, $total_cancelled_orders orders cancelled";
        
        // Check if this is a CLI call or web request
        if (is_cli()) {
            echo "$message\n";
        } else {
            // Return JSON for AJAX calls or redirect for web interface
            if ($this->input->is_ajax_request()) {
                echo json_encode([
                    'status' => 'success',
                    'message' => $message,
                    'patients_processed' => $processed_patients,
                    'suites_processed' => $processed_suites
                ]);
            } else {
                // For web interface, set flash message and redirect
                $this->session->set_flashdata('success', $message);
                redirect('Orderportal/Hospitalconfig/List');
            }
        }
        
        log_message('info', $message);
    }
    
	
	function onboardingForm($id='',$idType='person'){
	  
    $conditions['listtype'] = 'floor';
     $conditions['is_deleted'] = 0;
    $data['floor_numbers']  = $this->common_model->fetchRecordsDynamically('foodmenuconfig','',$conditions);
    
    
    $conditions['listtype'] = 'allergen';
    
    $data['allergies'] = $this->common_model->fetchRecordsDynamically('foodmenuconfig',['id','name'],$conditions);
    
    $conditionsB['status'] = '1';
    $conditionsB['is_deleted'] = '0';      // Exclude deleted suites
    $conditionsB['is_vaccant'] = '1';      // Only show vacant/available suites

    if($id !='' && $idType=='person'){
    $conditionsP =  array('id' => $id);
    $patientDetails = $this->common_model->fetchRecordsDynamically('people','',$conditionsP); 
    $data['patientDetails'] = (isset($patientDetails[0]) && !empty($patientDetails) ? reset($patientDetails) : array());   
    $conditionsB['floor'] = $patientDetails[0]['floor_number'];
   
    }else{
       $data['patientDetails'] = array();
    }
    
    if($id !='' && $idType =='suite'){
      $data['selected_suite'] = $id;  
      $conditionsSuites['id'] = $id;
      $data['selectedFloor'] = $this->common_model->fetchRecordsDynamically('suites',['floor'],$conditionsSuites);
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
	
	// edit suite
	public function editBed($bedId) {
    $conditions['listtype'] = 'floor';
    $floorListData = $this->common_model->fetchRecordsDynamically('foodmenuconfig', '', $conditions);
    $data['floorLists'] = $floorListData;

    $conditionsP = array('status' => 1, 'id' => $bedId);
    $bedDetails = $this->common_model->fetchRecordsDynamically('suites', '', $conditionsP);
    $data['bedDetails'] = (isset($bedDetails[0]) && !empty($bedDetails) ? reset($bedDetails) : array());

    // Check if request is AJAX
    $response = array(
            'status' => !empty($data['bedDetails']) ? 'success' : 'error',
            'bedDetails' => $data['bedDetails'],
            'floorLists' => $data['floorLists'],
            'message' => !empty($data['bedDetails']) ? 'Suite data retrieved' : 'Suite not found'
        );
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
}

public function updateSuite() {
    $this->load->helper('custom');
    $bedId = $this->input->post('bedId');
    $userId = $this->ion_auth->user()->row()->id;
    $userEmail = $this->ion_auth->user()->row()->email;
    $userIP = $this->input->ip_address();
    
    $oldSuite = $this->tenantDb->where('id', $bedId)->get('suites')->row_array();
    $oldSuiteNumber = $oldSuite ? $oldSuite['bed_no'] : 'Unknown';
    $oldFloor = $oldSuite ? $oldSuite['floor'] : 'Unknown';
    
    $data = array(
        'bed_no' => $this->input->post('bed_no'),
        'floor' => $this->input->post('floor'),
        'suite_pin' => trim($this->input->post('suite_pin')),
        'notes' => $this->input->post('notes')
    );
    
    $this->common_model->commonRecordUpdate('suites', 'id', $bedId, $data);
    $affected_rows = $this->tenantDb->affected_rows();
    
    if($affected_rows > 0){
        log_message('info', "SUITE UPDATE AJAX: Suite updated successfully. Suite ID={$bedId}, Old Suite Number={$oldSuiteNumber}, New Suite Number={$data['bed_no']}, Old Floor={$oldFloor}, New Floor={$data['floor']}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
    } else {
        log_message('warning', "SUITE UPDATE AJAX: No rows affected. Suite ID={$bedId}, Suite Number={$data['bed_no']}, Floor={$data['floor']}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
    }
    
    if ($this->input->is_ajax_request()) {
        $response = array(
            'status' => 'success',
            'message' => 'Suite updated successfully'
        );
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
    } else {
        redirect('Orderportal/Hospitalconfig/List');
    }
}

public function deleteBed() {
    $this->load->helper('custom');
    $bedId = $this->input->post('id');
    $userId = $this->ion_auth->user()->row()->id;
    $userEmail = $this->ion_auth->user()->row()->email;
    $userIP = $this->input->ip_address();
    
    $suite = $this->tenantDb->where('id', $bedId)->get('suites')->row_array();
    $suiteNumber = $suite ? $suite['bed_no'] : 'Unknown';
    $floor = $suite ? $suite['floor'] : 'Unknown';
    
    $deleteData = array(
        'is_deleted' => 1
    );
    $this->common_model->commonRecordUpdate('suites', 'id', $bedId, $deleteData);
    $affected_rows = $this->tenantDb->affected_rows();

    if($affected_rows > 0){
        log_message('info', "SUITE DELETE: Suite deleted successfully. Suite ID={$bedId}, Suite Number={$suiteNumber}, Floor={$floor}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
    } else {
        log_message('warning', "SUITE DELETE: No rows affected. Suite ID={$bedId}, Suite Number={$suiteNumber}, Floor={$floor}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
    }

    $response = array(
            'status' => 'success',
            'message' => 'Suite deleted successfully'
        );
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
}

/**
 * Reactivate a deleted suite
 */
public function reactivateSuite() {
    // Set JSON header
    $this->output->set_content_type('application/json');
    
    try {
        if (!$this->ion_auth->logged_in()) {
            echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
            return;
        }
        
        $bedId = $this->input->post('id');
        
        if (empty($bedId)) {
            echo json_encode(['status' => 'error', 'message' => 'Suite ID is required']);
            return;
        }
        
        $this->load->helper('custom');
        $userId = $this->ion_auth->user()->row()->id;
        $userEmail = $this->ion_auth->user()->row()->email;
        $userIP = $this->input->ip_address();
        
        // Log the attempt
        log_message('info', "SUITE REACTIVATE ATTEMPT: Attempting to reactivate suite. Suite ID={$bedId}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
        
        // Check if suite exists and is deleted
        $suite = $this->tenantDb->where('id', $bedId)
                              ->where('is_deleted', 1)
                              ->get('suites')
                              ->row_array();
        
        if (empty($suite)) {
            log_message('error', "SUITE REACTIVATE FAILED: Deleted suite not found. Suite ID={$bedId}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
            echo json_encode(['status' => 'error', 'message' => 'Deleted suite not found']);
            return;
        }
        
        log_message('info', "SUITE REACTIVATE: Found deleted suite. Suite ID={$bedId}, Suite Number={$suite['bed_no']}, Floor={$suite['floor']}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
        
        // Check for duplicate active suite with same number (only check active suites, not deleted ones)
        $existing_active = $this->tenantDb->where('bed_no', $suite['bed_no'])
                                        ->where('location_id', $this->selected_location_id)
                                        ->where('is_deleted', 0) // Only check truly active suites
                                        ->where('id !=', $bedId) // Exclude the current suite being reactivated
                                        ->get('suites')
                                        ->result_array();
        
        if (!empty($existing_active)) {
            log_message('warning', "SUITE REACTIVATE BLOCKED: Active suite with same number exists. Suite ID={$bedId}, Suite Number={$suite['bed_no']}, Floor={$suite['floor']}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
            echo json_encode([
                'status' => 'error', 
                'message' => 'Cannot reactivate: Suite number "' . htmlspecialchars($suite['bed_no']) . '" already exists as an active suite. Please run data cleanup first.'
            ]);
            return;
        }
        
        // Reactivate the suite
        $reactivateData = array(
            'is_deleted' => 0
        );
        
        $this->tenantDb->where('id', $bedId);
        $result = $this->tenantDb->update('suites', $reactivateData);
        $affected_rows = $this->tenantDb->affected_rows();
        
        if ($result && $affected_rows > 0) {
            log_message('info', "SUITE REACTIVATE SUCCESS: Suite reactivated successfully. Suite ID={$bedId}, Suite Number={$suite['bed_no']}, Floor={$suite['floor']}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Timestamp=" . australia_datetime());
            echo json_encode([
                'status' => 'success',
                'message' => 'Suite "' . htmlspecialchars($suite['bed_no']) . '" has been reactivated successfully'
            ]);
        } else {
            $db_error = $this->tenantDb->error();
            log_message('error', "SUITE REACTIVATE FAILED: Database error. Suite ID={$bedId}, Suite Number={$suite['bed_no']}, Floor={$suite['floor']}, Location ID={$this->selected_location_id}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, DB Error=" . json_encode($db_error) . ", Timestamp=" . australia_datetime());
            echo json_encode([
                'status' => 'error', 
                'message' => 'Failed to reactivate suite. Database error occurred.',
                'debug' => $this->tenantDb->last_query()
            ]);
        }
        
    } catch (Exception $e) {
        log_message('error', "SUITE REACTIVATE EXCEPTION: Exception occurred. Suite ID={$bedId}, User ID={$userId}, User Email={$userEmail}, IP={$userIP}, Error=" . $e->getMessage() . ", Timestamp=" . australia_datetime());
        echo json_encode([
            'status' => 'error', 
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
}

/**
 * Clean up duplicate suite data - removes duplicate entries where same suite number exists as both active and deleted
 */
public function cleanupDuplicateSuites() {
    if (!$this->ion_auth->logged_in()) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        return;
    }
    
    // Check if user has admin privileges (optional security check)
    if (!$this->ion_auth->is_admin()) {
        echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
        return;
    }
    
    try {
        // Find all suite numbers that have both active and deleted entries
        $duplicates_query = "
            SELECT bed_no, location_id, COUNT(*) as count,
                   GROUP_CONCAT(id ORDER BY is_deleted ASC, id ASC) as suite_ids,
                   GROUP_CONCAT(is_deleted ORDER BY is_deleted ASC, id ASC) as deleted_statuses
            FROM suites 
            WHERE status = 1 
            AND location_id = ? 
            GROUP BY bed_no, location_id 
            HAVING COUNT(*) > 1
        ";
        
        $duplicates = $this->tenantDb->query($duplicates_query, [$this->selected_location_id])->result_array();
        
        $cleaned_count = 0;
        $cleanup_details = [];
        
        foreach ($duplicates as $duplicate) {
            $suite_ids = explode(',', $duplicate['suite_ids']);
            $deleted_statuses = explode(',', $duplicate['deleted_statuses']);
            
            // Check if we have both active (0) and deleted (1) entries
            if (in_array('0', $deleted_statuses) && in_array('1', $deleted_statuses)) {
                // Keep the active one (is_deleted = 0), remove the deleted ones (is_deleted = 1)
                $active_suite_id = null;
                $deleted_suite_ids = [];
                
                for ($i = 0; $i < count($suite_ids); $i++) {
                    if ($deleted_statuses[$i] == '0') {
                        $active_suite_id = $suite_ids[$i];
                    } else {
                        $deleted_suite_ids[] = $suite_ids[$i];
                    }
                }
                
                // Remove the deleted duplicates permanently
                if (!empty($deleted_suite_ids)) {
                    foreach ($deleted_suite_ids as $deleted_id) {
                        $this->common_model->commonRecordDelete('suites', $deleted_id, 'id');
                        $cleaned_count++;
                    }
                    
                    $cleanup_details[] = [
                        'suite_number' => $duplicate['bed_no'],
                        'kept_active_id' => $active_suite_id,
                        'removed_deleted_ids' => $deleted_suite_ids
                    ];
                }
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => "Cleaned up {$cleaned_count} duplicate suite entries",
            'details' => $cleanup_details,
            'total_duplicates_found' => count($duplicates),
            'duplicates_cleaned' => $cleaned_count
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Error during cleanup: ' . $e->getMessage()
        ]);
    }
}

/**
 * Simple test endpoint to verify JSON responses work
 */
public function testEndpoint() {
    $this->output->set_content_type('application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'Test endpoint working correctly',
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $this->input->method()
    ]);
}

/**
 * Debug suite data - shows details about a specific suite
 */
public function debugSuite($suiteId = null) {
        // SECURITY: Only allow in development/testing environments
        if (ENVIRONMENT === 'production') {
            show_404();
            return;
        }
    $this->output->set_content_type('application/json');
    
    if (!$this->ion_auth->logged_in()) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        return;
    }
    
    if (empty($suiteId)) {
        $suiteId = $this->input->get('id') ?: $this->input->post('id');
    }
    
    if (empty($suiteId)) {
        echo json_encode(['status' => 'error', 'message' => 'Suite ID required']);
        return;
    }
    
    try {
        // Get the specific suite
        $suite = $this->tenantDb->where('id', $suiteId)->get('suites')->row_array();
        
        // Get all suites with same bed number
        $all_same_number = [];
        if (!empty($suite)) {
            $all_same_number = $this->tenantDb
                ->where('bed_no', $suite['bed_no'])
                ->where('location_id', $this->selected_location_id)
                ->get('suites')
                ->result_array();
        }
        
        echo json_encode([
            'status' => 'success',
            'requested_suite_id' => $suiteId,
            'suite_data' => $suite,
            'all_suites_with_same_number' => $all_same_number,
            'location_id' => $this->selected_location_id
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Debug error: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get duplicate suite analysis - shows which suites have duplicates
 */
public function analyzeDuplicateSuites() {
    if (!$this->ion_auth->logged_in()) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        return;
    }
    
    try {
        // Find all suite numbers that have multiple entries
        $analysis_query = "
            SELECT bed_no, location_id, COUNT(*) as count,
                   GROUP_CONCAT(CONCAT('ID:', id, '(', CASE WHEN is_deleted=1 THEN 'DELETED' ELSE 'ACTIVE' END, ')') SEPARATOR ', ') as entries
            FROM suites 
            WHERE status = 1 
            AND location_id = ? 
            GROUP BY bed_no, location_id 
            HAVING COUNT(*) > 1
            ORDER BY bed_no
        ";
        
        $duplicates = $this->tenantDb->query($analysis_query, [$this->selected_location_id])->result_array();
        
        echo json_encode([
            'status' => 'success',
            'duplicates' => $duplicates,
            'total_duplicate_groups' => count($duplicates)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Error during analysis: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get vacant suites for transfer dropdown
 */
public function getVacantSuites() {
    $this->output->set_content_type('application/json');
    
    if (!$this->ion_auth->logged_in()) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        return;
    }
    
    try {
        $current_suite_id = $this->input->post('current_suite_id');
        
        // Get vacant suites (excluding the current suite being transferred from)
        $conditions = array(
            'status' => 1,
            'is_deleted' => 0,
            'is_vaccant' => 1,
            'location_id' => $this->selected_location_id
        );
        
        $vacant_suites = $this->tenantDb->where($conditions);
        
        // Exclude current suite if provided
        if (!empty($current_suite_id)) {
            $this->tenantDb->where('id !=', $current_suite_id);
        }
        
        $suites = $this->tenantDb->get('suites')->result_array();
        
        // Get floor names for each suite
        $suites_with_floors = array();
        foreach ($suites as $suite) {
            $floor_conditions = array('id' => $suite['floor'], 'listtype' => 'floor');
            $floor_data = $this->common_model->fetchRecordsDynamically('foodmenuconfig', ['name'], $floor_conditions);
            $floor_name = !empty($floor_data) ? $floor_data[0]['name'] : 'Unknown Floor';
            
            $suites_with_floors[] = array(
                'id' => $suite['id'],
                'bed_no' => $suite['bed_no'],
                'floor_name' => $floor_name
            );
        }
        
        echo json_encode([
            'status' => 'success',
            'suites' => $suites_with_floors
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Error loading vacant suites: ' . $e->getMessage()
        ]);
    }
}

/**
 * Transfer client from one suite to another
 */
public function transferClient() {
    $this->output->set_content_type('application/json');
    
    if (!$this->ion_auth->logged_in()) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        return;
    }
    
    try {
        $source_suite_id = $this->input->post('source_suite_id');
        $destination_suite_id = $this->input->post('destination_suite_id');
        
        if (empty($source_suite_id) || empty($destination_suite_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Source and destination suite IDs are required']);
            return;
        }
        
        if ($source_suite_id == $destination_suite_id) {
            echo json_encode(['status' => 'error', 'message' => 'Source and destination suites cannot be the same']);
            return;
        }
        
        // Start transaction
        $this->tenantDb->trans_start();
        
        // Get source suite details
        $source_suite = $this->tenantDb->where('id', $source_suite_id)
                                      ->where('status', 1)
                                      ->where('is_deleted', 0)
                                      ->where('location_id', $this->selected_location_id)
                                      ->get('suites')
                                      ->row_array();
        
        if (empty($source_suite)) {
            echo json_encode(['status' => 'error', 'message' => 'Source suite not found']);
            return;
        }
        
        // Get destination suite details
        $destination_suite = $this->tenantDb->where('id', $destination_suite_id)
                                           ->where('status', 1)
                                           ->where('is_deleted', 0)
                                           ->where('location_id', $this->selected_location_id)
                                           ->get('suites')
                                           ->row_array();
        
        if (empty($destination_suite)) {
            echo json_encode(['status' => 'error', 'message' => 'Destination suite not found']);
            return;
        }
        
        // Check if source suite is actually occupied
        if ($source_suite['is_vaccant'] == 1) {
            echo json_encode(['status' => 'error', 'message' => 'Source suite is vacant - no client to transfer']);
            return;
        }
        
        // Check if destination suite is actually vacant
        if ($destination_suite['is_vaccant'] == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Destination suite is already occupied']);
            return;
        }
        
        // Get active client from source suite
        $today = date('Y-m-d');
        $client_conditions = array(
            'status' => 1,
            'suite_number' => $source_suite_id
        );
        
        log_message('info', "Looking for clients in suite {$source_suite_id} with conditions: " . json_encode($client_conditions));
        
        $clients = $this->common_model->fetchRecordsDynamically('people', '', $client_conditions);
        
        log_message('info', "Found " . count($clients) . " clients in suite {$source_suite_id}");
        
        // Filter for active clients (no discharge date, future discharge date, or same-day discharge)
        $active_client = null;
        if (!empty($clients)) {
            foreach ($clients as $client) {
                $discharge_date = $client['date_of_discharge'];
                log_message('info', "Client ID {$client['id']} discharge date: " . ($discharge_date ?: 'NULL'));
                
                if (empty($discharge_date) || $discharge_date >= $today) {
                    $active_client = $client;
                    log_message('info', "Selected active client ID {$client['id']}");
                    break; // Take the first active client
                }
            }
        }
        
        if (empty($active_client)) {
            log_message('error', "No active client found in source suite {$source_suite_id}");
            echo json_encode([
                'status' => 'error', 
                'message' => 'No active client found in source suite',
                'debug' => [
                    'source_suite_id' => $source_suite_id,
                    'clients_found' => count($clients),
                    'today' => $today
                ]
            ]);
            return;
        }
        
        // Verify the client record exists and get current data
        $client_verification = $this->tenantDb->where('id', $active_client['id'])
                                            ->where('status', 1)
                                            ->get('people')
                                            ->row_array();
        
        if (empty($client_verification)) {
            log_message('error', "Client ID {$active_client['id']} not found or inactive");
            echo json_encode([
                'status' => 'error', 
                'message' => 'Client record not found or inactive',
                'debug' => [
                    'client_id' => $active_client['id'],
                    'client_status' => 'not_found_or_inactive'
                ]
            ]);
            return;
        }
        
        log_message('info', "Client verification successful. Current suite: {$client_verification['suite_number']}, Target suite: {$destination_suite_id}");
        
        // Update client's suite number using direct database update
        $client_update = array(
            'suite_number' => $destination_suite_id,
            'date_modified' => date('Y-m-d H:i:s')
        );
        
        // Debug: Log the client update attempt
        log_message('info', "Attempting to update client ID {$active_client['id']} with suite number {$destination_suite_id}");
        
        // Use direct database update instead of common model
        $this->tenantDb->where('id', $active_client['id']);
        $client_result = $this->tenantDb->update('people', $client_update);
        
        if (!$client_result || $this->tenantDb->affected_rows() == 0) {
            $this->tenantDb->trans_rollback();
            $db_error = $this->tenantDb->error();
            log_message('error', "Failed to update client record. DB Error: " . json_encode($db_error));
            log_message('error', "Last query: " . $this->tenantDb->last_query());
            log_message('error', "Affected rows: " . $this->tenantDb->affected_rows());
            echo json_encode([
                'status' => 'error', 
                'message' => 'Failed to update client record',
                'debug' => [
                    'client_id' => $active_client['id'],
                    'destination_suite_id' => $destination_suite_id,
                    'db_error' => $db_error,
                    'last_query' => $this->tenantDb->last_query(),
                    'affected_rows' => $this->tenantDb->affected_rows(),
                    'update_result' => $client_result
                ]
            ]);
            return;
        }
        
        log_message('info', "Successfully updated client ID {$active_client['id']} suite number to {$destination_suite_id}. Affected rows: " . $this->tenantDb->affected_rows());
        
        // Update source suite to vacant using direct database update
        $source_update = array(
            'is_vaccant' => 1
        );
        
        $this->tenantDb->where('id', $source_suite_id);
        $source_result = $this->tenantDb->update('suites', $source_update);
        
        if (!$source_result || $this->tenantDb->affected_rows() == 0) {
            $this->tenantDb->trans_rollback();
            log_message('error', "Failed to update source suite {$source_suite_id}");
            echo json_encode(['status' => 'error', 'message' => 'Failed to update source suite']);
            return;
        }
        
        log_message('info', "Successfully updated source suite {$source_suite_id} to vacant");
        
        // Update destination suite to occupied using direct database update
        $destination_update = array(
            'is_vaccant' => 0
        );
        
        $this->tenantDb->where('id', $destination_suite_id);
        $destination_result = $this->tenantDb->update('suites', $destination_update);
        
        if (!$destination_result || $this->tenantDb->affected_rows() == 0) {
            $this->tenantDb->trans_rollback();
            log_message('error', "Failed to update destination suite {$destination_suite_id}");
            echo json_encode(['status' => 'error', 'message' => 'Failed to update destination suite']);
            return;
        }
        
        log_message('info', "Successfully updated destination suite {$destination_suite_id} to occupied");
        
        // Complete transaction
        $this->tenantDb->trans_complete();
        
        if ($this->tenantDb->trans_status() === FALSE) {
            echo json_encode(['status' => 'error', 'message' => 'Transaction failed']);
            return;
        }
        
        // ═══════════════════════════════════════════════════════════════════
        // TRANSFER ORDERS: Move all future/active orders from old suite to new suite
        // This ensures the patient's meal orders follow them to their new room
        // ═══════════════════════════════════════════════════════════════════
        $ordersTransferred = $this->transferSuiteOrders($source_suite_id, $destination_suite_id, $active_client['name'] ?? 'Unknown');
        
        // Log the transfer
        log_message('info', "Client transferred: Suite {$source_suite['bed_no']} to Suite {$destination_suite['bed_no']} for client ID {$active_client['id']}. Orders transferred: {$ordersTransferred}");
        
        // ═══════════════════════════════════════════════════════════════════
        // LOG TO AUDIT TRAIL: Room Transfer via drag-drop
        // ═══════════════════════════════════════════════════════════════════
        $this->logTransferToAuditTrailFromConfig(
            $active_client['id'],
            $active_client['name'] ?? 'Unknown',
            $source_suite_id,
            $source_suite,
            $destination_suite_id,
            $destination_suite,
            $ordersTransferred
        );
        
        // ═══════════════════════════════════════════════════════════════════
        // NOTIFY KITCHEN: Room transfer occurred
        // ═══════════════════════════════════════════════════════════════════
        $this->load->helper('notification');
        $this->load->helper('custom');
        $notification_msg = "🔄 Room Transfer: Patient '{$active_client['name']}' moved from {$source_suite['bed_no']} to {$destination_suite['bed_no']} at " . australia_datetime() . ". {$ordersTransferred} meal order(s) updated.";
        createNotification($this->tenantDb, 1, $this->selected_location_id, 'notice', $notification_msg);
        
        echo json_encode([
            'status' => 'success',
            'message' => "Client successfully transferred from Suite {$source_suite['bed_no']} to Suite {$destination_suite['bed_no']}" . ($ordersTransferred > 0 ? ". {$ordersTransferred} order(s) transferred." : ""),
            'client_name' => isset($active_client['first_name']) ? $active_client['first_name'] . ' ' . $active_client['last_name'] : 'Unknown Client',
            'source_suite' => $source_suite['bed_no'],
            'destination_suite' => $destination_suite['bed_no'],
            'orders_transferred' => $ordersTransferred
        ]);
        
    } catch (Exception $e) {
        $this->tenantDb->trans_rollback();
        log_message('error', 'Transfer client error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Transfer failed: ' . $e->getMessage()
        ]);
    }
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
    
    log_message('info', "ORDER TRANSFER: Starting order transfer from {$source_name} (ID:{$source_suite_id}) to {$dest_name} (ID:{$destination_suite_id}) for patient '{$patient_name}'. User=" . ($this->session->userdata('username') ?: 'UNKNOWN') . " at " . australia_datetime());
    
    // ═══════════════════════════════════════════════════════════════════
    // PART A: Find and transfer LEGACY orders (orders.bed_id = suite_id)
    // ═══════════════════════════════════════════════════════════════════
    $this->tenantDb->where('bed_id', $source_suite_id);
    $this->tenantDb->where('date >=', $today);
    $this->tenantDb->where('status !=', 0); // Not cancelled
    $this->tenantDb->where('is_delivered !=', 1); // Not delivered
    $legacy_orders = $this->tenantDb->get('orders')->result_array();
    
    log_message('info', "ORDER TRANSFER: Found " . count($legacy_orders) . " legacy order(s) for {$source_name}");
    
    foreach ($legacy_orders as $order) {
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
            log_message('info', "ORDER TRANSFER: Successfully transferred legacy order ID={$order_id} (date={$order_date}) from {$source_name} to {$dest_name}");
            
        } catch (Exception $e) {
            log_message('error', "ORDER TRANSFER ERROR: Failed to transfer legacy order ID={$order_id}. Error: " . $e->getMessage());
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════
    // PART B: Find and transfer FLOOR CONSOLIDATED orders 
    //         (suite_id is stored in suite_order_details, NOT orders.bed_id)
    // ═══════════════════════════════════════════════════════════════════
    if ($this->tenantDb->table_exists('suite_order_details')) {
        // Find suite_order_details entries for the source suite
        $query = "SELECT DISTINCT sod.id as suite_detail_id, sod.floor_order_id, o.order_id, o.date
                  FROM suite_order_details sod
                  INNER JOIN orders o ON o.order_id = sod.floor_order_id
                  WHERE sod.suite_id = ?
                  AND sod.status = 'active'
                  AND o.date >= ?
                  AND o.status != 0
                  AND o.is_delivered != 1
                  AND o.is_floor_consolidated = 1";
        $floor_order_result = $this->tenantDb->query($query, [$source_suite_id, $today]);
        $floor_orders = $floor_order_result->result_array();
        
        log_message('info', "ORDER TRANSFER: Found " . count($floor_orders) . " floor consolidated order(s) for {$source_name}");
        
        foreach ($floor_orders as $floor_order) {
            $suite_detail_id = $floor_order['suite_detail_id'];
            $floor_order_id = $floor_order['floor_order_id'];
            $order_date = $floor_order['date'];
            
            try {
                // Update suite_order_details.suite_id - THIS IS THE CRITICAL ONE for floor orders
                $this->tenantDb->where('id', $suite_detail_id);
                $this->tenantDb->update('suite_order_details', ['suite_id' => $destination_suite_id]);
                
                // Update orders_to_patient_options - change bed_id for items linked to this suite_order_detail
                $this->tenantDb->where('suite_order_detail_id', $suite_detail_id);
                $this->tenantDb->update('orders_to_patient_options', ['bed_id' => $destination_suite_id]);
                
                // Also update by order_id + bed_id for any items that might not have suite_order_detail_id set
                $this->tenantDb->where('order_id', $floor_order_id);
                $this->tenantDb->where('bed_id', $source_suite_id);
                $this->tenantDb->update('orders_to_patient_options', ['bed_id' => $destination_suite_id]);
                
                // Update orders_to_comments - change bed_id
                $this->tenantDb->where('order_id', $floor_order_id);
                $this->tenantDb->where('bed_id', $source_suite_id);
                $this->tenantDb->update('orders_to_comments', ['bed_id' => $destination_suite_id]);
                
                // Update delivery status tables
                if ($this->tenantDb->table_exists('orders_to_deliverystatus')) {
                    $this->tenantDb->where('order_id', $floor_order_id);
                    $this->tenantDb->where('bed_id', $source_suite_id);
                    $this->tenantDb->update('orders_to_deliverystatus', ['bed_id' => $destination_suite_id]);
                }
                
                if ($this->tenantDb->table_exists('orders_to_packagestatus')) {
                    $this->tenantDb->where('order_id', $floor_order_id);
                    $this->tenantDb->where('bed_id', $source_suite_id);
                    $this->tenantDb->update('orders_to_packagestatus', ['bed_id' => $destination_suite_id]);
                }
                
                // Update menu_item_comments table if it exists
                if ($this->tenantDb->table_exists('menu_item_comments')) {
                    $this->tenantDb->where('order_id', $floor_order_id);
                    $this->tenantDb->where('bed_id', $source_suite_id);
                    $this->tenantDb->update('menu_item_comments', ['bed_id' => $destination_suite_id]);
                }
                
                $orders_transferred++;
                log_message('info', "ORDER TRANSFER: Successfully transferred floor order (suite_detail_id={$suite_detail_id}, floor_order_id={$floor_order_id}, date={$order_date}) from {$source_name} to {$dest_name}");
                
            } catch (Exception $e) {
                log_message('error', "ORDER TRANSFER ERROR: Failed to transfer floor order suite_detail_id={$suite_detail_id}. Error: " . $e->getMessage());
            }
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════
    // PART C: Notification about the transfer
    // NOTE: Notification is NOT sent here to avoid duplicates.
    // The calling method (transferClient) already sends a "Room Transfer" notification.
    // ═══════════════════════════════════════════════════════════════════
    
    log_message('info', "ORDER TRANSFER COMPLETE: Transferred {$orders_transferred} order(s) from {$source_name} to {$dest_name} for patient '{$patient_name}'");
    
    return $orders_transferred;
}

/**
 * Log room transfer to audit trail from Hospitalconfig controller
 * (Used for drag-drop transfers on the portal page)
 */
private function logTransferToAuditTrailFromConfig($patientId, $patientName, $oldSuiteId, $oldSuite, $newSuiteId, $newSuite, $ordersTransferred = 0) {
    log_message('info', "AUDIT TRANSFER (Config): Starting audit log for patient {$patientId} ({$patientName}), from suite {$oldSuiteId} to {$newSuiteId}");
    
    try {
        // Load the AuditTrail model from Orderportal module
        $this->load->model('Orderportal/AuditTrail_model', 'AuditTrail_model');
        $this->load->helper('custom');
        
        // Get floor IDs from suites — column is 'floor' (varchar), NOT 'floor_id'
        $oldFloorId = $oldSuite['floor'] ?? null;
        $newFloorId = $newSuite['floor'] ?? null;
        
        // Get floor names from foodmenuconfig
        $old_floor_name = "Floor {$oldFloorId}";
        if (!empty($oldFloorId)) {
            $old_floor_details = $this->tenantDb->where('id', $oldFloorId)
                                               ->where('listtype', 'floor')
                                               ->get('foodmenuconfig')
                                               ->row_array();
            if (!empty($old_floor_details)) {
                $old_floor_name = $old_floor_details['name'];
            }
        }
        
        $new_floor_name = "Floor {$newFloorId}";
        if (!empty($newFloorId)) {
            $new_floor_details = $this->tenantDb->where('id', $newFloorId)
                                               ->where('listtype', 'floor')
                                               ->get('foodmenuconfig')
                                               ->row_array();
            if (!empty($new_floor_details)) {
                $new_floor_name = $new_floor_details['name'];
            }
        }
        
        $notes = "Transferred from {$oldSuite['bed_no']} to {$newSuite['bed_no']} via drag-drop";
        if ($ordersTransferred > 0) {
            $notes .= ". {$ordersTransferred} meal order(s) updated to new room.";
        }
        
        log_message('info', "AUDIT TRANSFER (Config): Suite details - Old: {$oldSuite['bed_no']} (Floor: {$old_floor_name}), New: {$newSuite['bed_no']} (Floor: {$new_floor_name})");
        
        $auditResult = $this->AuditTrail_model->logTransfer(
            $patientId,
            $patientName,
            $oldSuiteId,
            $oldSuite['bed_no'],
            $oldFloorId,
            $old_floor_name,
            $newSuiteId,
            $newSuite['bed_no'],
            $newFloorId,
            $new_floor_name,
            $ordersTransferred,
            $notes
        );
        
        if ($auditResult) {
            log_message('info', "AUDIT TRANSFER (Config): Successfully logged transfer to audit trail. Audit ID: {$auditResult}");
        } else {
            log_message('error', "AUDIT TRANSFER (Config): logTransfer() returned false/null for patient {$patientId}");
        }
        
    } catch (Exception $e) {
        log_message('error', 'AUDIT TRANSFER (Config): Exception caught - ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    }
}

/**
 * Debug method to check suite and client data
 */
public function debugTransferData($suite_id = null) {
        // SECURITY: Only allow in development/testing environments
        if (ENVIRONMENT === 'production') {
            show_404();
            return;
        }
    $this->output->set_content_type('application/json');
    
    if (!$this->ion_auth->logged_in()) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        return;
    }
    
    try {
        if (empty($suite_id)) {
            $suite_id = $this->input->get('suite_id') ?: $this->input->post('suite_id');
        }
        
        if (empty($suite_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Suite ID required']);
            return;
        }
        
        // Get suite details
        $suite = $this->tenantDb->where('id', $suite_id)
                              ->where('status', 1)
                              ->where('is_deleted', 0)
                              ->where('location_id', $this->selected_location_id)
                              ->get('suites')
                              ->row_array();
        
        // Get clients in this suite
        $clients = $this->tenantDb->where('suite_number', $suite_id)
                                 ->where('status', 1)
                                 ->get('people')
                                 ->result_array();
        
        // Get vacant suites
        $vacant_suites = $this->tenantDb->where('status', 1)
                                       ->where('is_deleted', 0)
                                       ->where('is_vaccant', 1)
                                       ->where('location_id', $this->selected_location_id)
                                       ->where('id !=', $suite_id)
                                       ->get('suites')
                                       ->result_array();
        
        echo json_encode([
            'status' => 'success',
            'suite_id' => $suite_id,
            'suite_data' => $suite,
            'clients_in_suite' => $clients,
            'vacant_suites_count' => count($vacant_suites),
            'vacant_suites' => $vacant_suites,
            'location_id' => $this->selected_location_id,
            'today' => date('Y-m-d')
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Debug error: ' . $e->getMessage()
        ]);
    }
}
    
}
    
    ?>