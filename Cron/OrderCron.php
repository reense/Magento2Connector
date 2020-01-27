<?php

namespace MailCampaigns\Connector\Cron;

class OrderCron {
 
 	protected $helper;
	protected $resource;
	protected $connection;
	protected $tn__mc_api_pages;
	protected $tn__mc_api_queue;
    protected $mcapi;
    protected $subscriberfactory;
	protected $quoterepository;
    protected $productrepository;
    protected $taxhelper;
	protected $quotefactory;
  
    public function __construct(
       	\MailCampaigns\Connector\Helper\Data $dataHelper,
        \Magento\Framework\App\ResourceConnection $Resource,
        \Magento\Newsletter\Model\SubscriberFactory $SubscriberFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
		\Magento\Quote\Model\QuoteRepository $quoteRepository,
		\Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Catalog\Helper\Data $taxHelper,
		\MailCampaigns\Connector\Helper\MailCampaigns_API $mcapi
    ) {
        $this->resource 	= $Resource;
		$this->helper 		= $dataHelper;
        $this->mcapi 		= $mcapi;
        $this->subscriberfactory 	= $SubscriberFactory;
		$this->quoterepository 	= $quoteRepository;
        $this->productrepository	= $productRepository;
		$this->quotefactory		= $quoteFactory;
        $this->taxhelper 		= $taxHelper;
    }
 
    public function execute() 
	{		
		//database connection
        $this->connection = $this->resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
		
        try{	
            // Get table names
            $tn__sales_flat_quote 		= $this->resource->getTableName('quote');
            $tn__sales_flat_order 		= $this->resource->getTableName('sales_order');
            $tn__sales_flat_quote_item 	= $this->resource->getTableName('quote_item');
			$tn__mc_api_status			= $this->resource->getTableName('mc_api_status');
			
			// default time
			$last_process_time 			= time() - 300; // default
			
			// select latest time
			$sql        = "SELECT datetime FROM ".$tn__mc_api_status." WHERE type = 'order_cron' ORDER BY datetime DESC LIMIT 1";
            $rows       = $this->connection->fetchAll($sql);
            foreach ($rows as $row) { $last_process_time = $row["datetime"]; }
			
			// delete old times
			$sql = "DELETE FROM `".$tn__mc_api_status."` WHERE type = 'order_cron'";
			$this->connection->query($sql);
			
			// save new one
			$this->connection->insert($tn__mc_api_status, array(
				'type'   		=> 'order_cron',
				'datetime'      => time()
			));
            
            // orders
			$order_sql        = "SELECT o.*
			FROM `".$tn__sales_flat_order."` AS o
			WHERE o.updated_at >= '".gmdate("Y-m-d H:i:s", $last_process_time)."' OR o.created_at >= '".gmdate("Y-m-d H:i:s", $last_process_time)."'
			ORDER BY  `o`.`updated_at` DESC";
			$order_rows       = $this->connection->fetchAll($order_sql);
			
			foreach ($order_rows as $order_row)
			{
				if ($this->helper->getConfig('mailcampaignsrealtimesync/general/import_orders', $order_row["store_id"]))
				{
					// Set API
					$this->mcapi->APIStoreID = $order_row["store_id"];
					$this->mcapi->APIKey 	 = $this->helper->getConfig('mailcampaignsapi/general/api_key', $this->mcapi->APIStoreID);
					$this->mcapi->APIToken 	 = $this->helper->getConfig('mailcampaignsapi/general/api_token', $this->mcapi->APIStoreID);
										
					// get order
					$objectMan =  \Magento\Framework\App\ObjectManager::getInstance();
					$order = $objectMan->create('Magento\Sales\Model\Order')->load($order_row["entity_id"]);
					
					$address = array();
					if(is_object($order->getShippingAddress()))
					{    
						$address = (array)$order->getShippingAddress()->getData();
					}    
					else
					if(is_object($order->getBillingAddress()))
					{
						$address = (array)$order->getBillingAddress()->getData();
					}
					
					$mc_order_data = $order;
					if ($mc_order_data["entity_id"] > 0)
					{					
						if(isset($mc_order_data["store_id"]))				{ $mc_data["store_id"] =		$mc_order_data["store_id"]				;}
						if(isset($mc_order_data["entity_id"]))				{ $mc_data["order_id"] =		$mc_order_data["entity_id"]				;}
						if(isset($mc_order_data["increment_id"]))			{ $mc_data["order_name"] =		$mc_order_data["increment_id"]			;}
						if(isset($mc_order_data["status"]))					{ $mc_data["order_status"] =	$mc_order_data["status"]				;}
						if(isset($mc_order_data["grand_total"]))			{ $mc_data["order_total"] =		$mc_order_data["grand_total"]			;}
						if(isset($mc_order_data["customer_id"]))			{ $mc_data["customer_id"] =		$mc_order_data["customer_id"]			;}
						if(isset($mc_order_data["quote_id"]))				{ $mc_data["quote_id"] =		$mc_order_data["quote_id"]				;}
						if(isset($mc_order_data["customer_email"]))			{ $mc_data["customer_email"] =	$mc_order_data["customer_email"]		;}
						if(isset($mc_order_data["customer_firstname"]))		{ $mc_data["firstname"] =		$mc_order_data["customer_firstname"]	;}
						if(isset($mc_order_data["customer_lastname"]))		{ $mc_data["lastname"] =		$mc_order_data["customer_lastname"]		;}
						if(isset($mc_order_data["customer_middlename"]))	{ $mc_data["middlename"] =		$mc_order_data["customer_middlename"]	;}
						if(isset($mc_order_data["customer_dob"]))			{ $mc_data["dob"] =				$mc_order_data["customer_dob"]			;}
						if(isset($address["telephone"]))					{ $mc_data["telephone"] =		$address["telephone"]					;}
						if(isset($address["street"]))						{ $mc_data["street"] =			$address["street"]						;}
						if(isset($address["postcode"]))						{ $mc_data["postcode"] =		$address["postcode"]					;}
						if(isset($address["city"]))							{ $mc_data["city"] =			$address["city"]						;}
						if(isset($address["region"]))						{ $mc_data["region"] =			$address["region"]						;}
						if(isset($address["country_id"]))					{ $mc_data["country_id"] =		$address["country_id"]					;}
						if(isset($address["company"]))						{ $mc_data["company"] =			$address["company"]						;}
						if(isset($mc_order_data["created_at"]))				{ $mc_data["created_at"] =		$mc_order_data["created_at"]			;}
						if(isset($mc_order_data["updated_at"]))				{ $mc_data["updated_at"] =		$mc_order_data["updated_at"]			;}
						if(is_object($order->getShippingAmount()))			$mc_data["shipping_amount"] = $order->getShippingAmount();
						if(is_object($order->getShippingInclTax()))			$mc_data["shipping_amount_incl_tax"] = $order->getShippingInclTax();
						if(is_object($order->getBaseSubtotalInclTax()))		$mc_data["subtotalInclTaxExclDiscount"] = $order->getBaseSubtotalInclTax();
						if(is_object($order->getDiscountAmount()))			$mc_data["discount"] = $order->getDiscountAmount();
	
						$this->mcapi->DirectOrQueueCall("update_magento_orders", $mc_data);
	
						// Get table names
						$tn__sales_flat_quote 					= $this->resource->getTableName('quote');
						$tn__sales_flat_order 					= $this->resource->getTableName('sales_order');
						$tn__sales_flat_order_item 				= $this->resource->getTableName('sales_order_item');
						$tn__sales_flat_quote_item 				= $this->resource->getTableName('quote_item');
						$tn__catalog_category_product 			= $this->resource->getTableName('catalog_category_product');
						$tn__catalog_category_entity_varchar 	= $this->resource->getTableName('catalog_category_entity_varchar');
						$tn__eav_entity_type 					= $this->resource->getTableName('eav_entity_type');
						$tn__catalog_category_entity 			= $this->resource->getTableName('catalog_category_entity');
	
						// order items
						$sql        = "SELECT o.entity_id as order_id, o.store_id, oi.product_id as product_id, oi.qty_ordered, oi.price, oi.name, oi.sku, o.customer_id
						FROM `".$tn__sales_flat_order."` AS o
						INNER JOIN `".$tn__sales_flat_order_item."` AS oi ON oi.order_id = o.entity_id
						WHERE o.entity_id = ".$mc_order_data["entity_id"]."
						ORDER BY  `o`.`updated_at` DESC";
						$rows       = $this->connection->fetchAll($sql);
	
						$mc_import_data = array(); $i = 0;
						foreach ($rows as $row)
						{
							foreach ($row as $key => $value)
							{
								if (!is_numeric($key)) $mc_import_data[$i][$key] = $value;
							}
						
							// get categories
							$categories = array();
							$category_data = array();
							if ($row["product_id"] > 0)
							{
								try
								{
									$objectMan =  \Magento\Framework\App\ObjectManager::getInstance();
									$product 	= $this->productrepository->getById($row["product_id"]);
									
									// Get Price Incl Tax
									$mc_import_data[$i]["price"] = $this->taxhelper->getTaxPrice($product, $mc_import_data[$i]["price"], true, NULL, NULL, NULL, $this->mcapi->APIStoreID, NULL, true);
									
									foreach ($product->getCategoryIds() as $category_id)
									{
										$categories[] = $category_id;
										$cat = $objectMan->create('Magento\Catalog\Model\Category')->load($category_id);
										$category_data[$category_id] = $cat->getName();
									}
								}
								catch (\Magento\Framework\Exception\NoSuchEntityException $e)
								{
									$this->mcapi->DebugCall($e->getMessage());
								}
								catch (Exception $e)
								{
									$this->mcapi->DebugCall($e->getMessage());
								}
							}
	
							$mc_import_data[$i]["categories"] = json_encode($categories);
	
							$i++;
						}
	
						if ($i > 0)
						{
							$response = $this->mcapi->DirectOrQueueCall("update_magento_categories", $category_data);
							$response = $this->mcapi->DirectOrQueueCall("update_magento_order_products", $mc_import_data);
						}
					}
					
					
				}
			}
        }
        catch (\Magento\Framework\Exception\NoSuchEntityException $e){
            $this->mcapi->DebugCall($e->getMessage());
        }
        catch (Exception $e){
            $this->mcapi->DebugCall($e->getMessage());
        }
		
		return $this;
    }
}