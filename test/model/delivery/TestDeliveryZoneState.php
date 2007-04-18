<?php
if(!defined('TEST_SUITE')) require_once dirname(__FILE__) . '/../../Initialize.php';

ClassLoader::import("application.model.delivery.State");
ClassLoader::import("application.model.delivery.DeliveryZone");
ClassLoader::import("application.model.delivery.DeliveryZoneCountry");
ClassLoader::import("application.model.delivery.DeliveryZoneState");

class TestDeliveryZoneState extends UnitTestCase
{
    private $autoincrements = array();

    /**
     * @var DeliveryZone
     */
    private $zone;
    
    /**
     * @var State
     */
    private $alaska;
    
    /**
     * Creole database connection wrapper
     *
     * @var Connection
     */
    private $db = null;
    
    public function __construct()
    {
        parent::__construct('delivery zone states tests');
        
	    $this->db = ActiveRecord::getDBConnection();
    }

    public function setUp()
	{
	    ActiveRecordModel::beginTransaction();	
	    
	    if(empty($this->autoincrements))
	    {
		    foreach(array('DeliveryZone', 'DeliveryZoneCountry', 'DeliveryZoneState') as $table)
		    {
				$res = $this->db->executeQuery("SHOW TABLE STATUS LIKE '$table'");
				$res->next();
				$this->autoincrements[$table] = (int)$res->getInt("Auto_increment");
		    }
	    }
	    
	    $this->zone = DeliveryZone::getNewInstance();
	    $this->zone->setValueByLang('name', 'en', ':TEST_ZONE');
	    $this->zone->isEnabled->set(1);
	    $this->zone->isFreeShipping->set(1);
	    $this->zone->save();
	    
	    $this->alaska = State::getInstanceByID(1, true, true); 
	}

	public function tearDown()
	{
	    ActiveRecordModel::rollback();	

	    foreach(array('DeliveryZone', 'DeliveryZoneCountry', 'DeliveryZoneState') as $table)
	    {
	        ActiveRecord::removeClassFromPool($table);
	        $this->db->executeUpdate("ALTER TABLE $table AUTO_INCREMENT=" . $this->autoincrements[$table]);
	    }	    
	}
	
	public function testCreateNewDeliveryZoneState()
	{
	    $deliveryState = DeliveryZoneState::getNewInstance($this->zone,  $this->alaska);
	    $deliveryState->save();
	    
	    $deliveryState->markAsNotLoaded();
	    $deliveryState->load();
	    
	    $this->assertEqual($deliveryState->deliveryZone->get(), $this->zone);
	    $this->assertTrue($deliveryState->state->get() === $this->alaska);
	}
	
	public function testDeleteDeliveryZoneState()
	{
	    $deliveryState = DeliveryZoneState::getNewInstance($this->zone,  $this->alaska);
	    $deliveryState->save();
	    
	    $this->assertTrue($deliveryState->isExistingRecord());
	    
	    $deliveryState->delete();
	    $deliveryState->markAsNotLoaded();
	    
	    try 
        { 
            $deliveryState->load(); 
            $this->fail(); 
        } 
        catch(Exception $e) 
        { 
            $this->pass(); 
        }
	}
}
?>