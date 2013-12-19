<?php
class MRoyer_Shopatron_Model_Observer
{
  protected $_sDebug;
  
  public function __construct()
  {
    // Set debug on for now.
    $this->_sDebug = false;
    $this->mfg_id = "00000.0";
    $this->_domain = "http://your.magento-installation.com/";
    Mage::log("Document Root: " . $_SERVER['DOCUMENT_ROOT']);
    include $_SERVER['DOCUMENT_ROOT'].'/XML-RPC/3.0.0/xmlrpc.inc';
    include $_SERVER['DOCUMENT_ROOT'].'/XML-RPC/compat/is_a.php';
  }
  
  /**
   * Compiles and sends the Shopatron RPC
   * @param   Varien_Event_Observer $observer
   * @return  MikeRoyer_Shopatron_Model_Observer
   */
  public function SendRPC($observer)
  {
    $server = new xmlrpc_client('/xmlServer.php','www.shopatron.com', 80);
    $message = $this->compileRPC();
    $result = $server->send($message);
    // For secure transmission -- did not work for me
    // $result = $server->send($message, 30, 'https');
    $this->DoResult($result,$message);
  }

  private function compileRPC() {
    // Set the empty arrays so we can push to them later.
    $rpc_message = array();
    $order_block = array();
    
    // get items (and num_items)
    $order_block = $this->compileItems();
    /**
        Number of items is calculated and passed with compileItems(), 
        but it precedes the XML Item Block. We store the number that is
        passed, and then delete it from the array.
    **/
    $num_items = $order_block["num_items"];
    unset($order_block["num_items"]);
    
    // Add mfg_id.cat_id & num_items
    $rpc_message[] = new xmlrpcval($$this->mfg_id, 'string');
    $rpc_message[] = new xmlrpcval($num_items, 'int');
    
    // Add the order block, which was compiled above
    $rpc_message[] = new xmlrpcval($order_block,"struct");
    
    // add language and currency
    $rpc_message[] = new xmlrpcval(array(
      "language_id" => new xmlrpcval(1,"int"),
      "currency_id" => new xmlrpcval(1,"int")
    ),"struct");
    
    $message = new xmlrpcmsg('examples.loadOrder',$rpc_message,"array");
    
    return $message;
  }

  private function compileItems() {
    $itemIncrement = 0;
    // TODO: Needs to be dynamic:
    $product_quantity = 1;
    $itemAvailable = "Y";
    
    $itemArray = array();
    
    // Get the Items
    $quote = Mage::getSingleton('checkout/session')->getQuote();
    $cartItems = $quote->getAllVisibleItems();
    foreach ($cartItems as $item)
    {
      $itemIncrement++;  // Needs to start at 1, but also needs to increment here so we get an accurate num_items
      $productId = $item->getProductId();
      $product_quantity = $item->getQty();
      $product = Mage::getModel('catalog/product')->load($productId);
      $productSKU = $product->getData('sku');
      $itemIncrementText = "item_" . $itemIncrement;
      // We want to sanitize the name so it can be passed with cURL
      $productName = filter_var($product->getData('name'),FILTER_SANITIZE_STRING,FILTER_FLAG_ENCODE_HIGH);
      $actual_price = $product->getData('price');
      
      // Determine if there is a Special Price
      if(is_numeric($product->getData('special_price'))) {
        $now = date("Y-m-d");
        $specialFrom = substr($product->getData('special_from_date'), 0, 10);
        $specialTo = substr($product->getData('special_to_date'), 0, 10);

        $special = false;
        
        // Check if within special price date range
        if (!empty($specialFrom) && !empty($specialTo)) {
            if ($now >= $specialFrom && $now <= $specialTo) $special = true;
        } elseif (!empty($specialFrom) && empty($specialTo)) {
            if ($now >= $specialFrom) $special = true;

        } elseif (empty($specialFrom) && !empty($specialTo)) {
            if ($now <= $specialTo) $special = true;
        }
        
        // Overwrite 'price' with 'special_price'
        if($special == true) {
          $actual_price = $product->getData('special_price');
        }
      }
      
      // Create item struct
      $itemAttr = array(
        "product_id" => new xmlrpcval($productSKU),
        "name" => new xmlrpcval($productName),
        "price" => new xmlrpcval(money_format("%i",$product->getData('price')), 'double'),
        "actual_price" => new xmlrpcval(money_format("%i",$actual_price), 'double'),
        "avg_margin" => new xmlrpcval(0, 'double'),
        "quantity" => new xmlrpcval($product_quantity,'int'),
        "weight" => new xmlrpcval(money_format("%i",$product->getData('weight')),'double'),
        "availability" => new xmlrpcval($itemAvailable)
      );
      
      // If extras, create option_text struct and add to item struct
      $itemOptions = $this->getProductOptions($item);
      if($itemOptions) {
        $optionsArray = array();
        $optionCount = 0;
        // Add options to options array
        foreach($itemOptions as $options) {
          $optionCount++;
          $optionsArray["option_" . $optionCount] = new xmlrpcval($options["label"] . ":" . $options["print_value"],"string");
        }
        
        // Add option_text struct to the Item Attributes array (defined as struct later)
        $itemAttr["option_text"] = new xmlrpcval($optionsArray,"struct");
      } // end if($itemOptions)
      
      $itemArray[$itemIncrementText] = new xmlrpcval($itemAttr,"struct");
      // Delete the item from the cart
      Mage::getSingleton('checkout/cart')->removeItem($item->getId());
    } // end foreach
    
    $itemArray["num_items"] = $itemIncrement;
    
    return $itemArray;
  }

  private function DoResult($result,$message) {
    // check for the result
    if(!$result && !is_numeric($result)) //the result hasn't been return or non-numeric
    {
      // Could not connect, fall back to old checkout
      Mage::log("Unable to connect to the Shopatron server for order ID: " . 0);
      //RedirectMe($this->_domain);
    }
    elseif($result->faultCode()) //the result has been returned but there is an error
    {
      if ($this->_sDebug == true)
      {
        Mage::log("\n\n\nXML-RPC Fault #".$result->faultCode().": ".$result->faultString());
        Mage::log("XML-RPC Payload: " . $message->serialize());
        // htmlentities($message->serialize())
        $this->RedirectMe($this->_domain . "/var/log/system.log");
      }
      else
      {
        // Log it
        Mage::log("\n\n\nXML-RPC Fault #".$result->faultCode().": ".$result->faultString());
        
        // Silent fallback to old checkout
      }
    }
    else //the result has been returned and new window is spawned with Shopatron
    {
      $value=$result->value();
      $orderID = $value->scalarval(); // collect the order_id
      if ($this->_sDebug == true)
      {
        Mage::log("Shopatron Checkout Success");
        Mage::log("XML-RPC Payload: " . $message->serialize());
        //Mage::log($value); 
        //Mage::log($result);
        $this->RedirectMe($this->_domain);
      }
      else
      {
        // redirect to Shopatron Checkout
        $this->SaveOrder($orderID);
        $this->RedirectMe("https://www.shopatron.com/xmlCheckout1.phtml?order_id=".$orderID);
      }
    }
  }

  private function getProductOptions(&$item) {
    $options = array();
    if ($optionIds = $item->getOptionByCode('option_ids')) {
        $options = array();
        foreach (explode(',', $optionIds->getValue()) as $optionId) {
            if ($option = $item->getProduct()->getOptionById($optionId)) {
  
                $quoteItemOption = $item->getOptionByCode('option_' . $option->getId());
  
                $group = $option->groupFactory($option->getType())
                    ->setOption($option)
                    ->setQuoteItemOption($quoteItemOption);
  
                $options[] = array(
                    'label' => $option->getTitle(),
                    'value' => $group->getFormattedOptionValue($quoteItemOption->getValue()),
                    'print_value' => $group->getPrintableOptionValue($quoteItemOption->getValue()),
                    'option_id' => $option->getId(),
                    'option_type' => $option->getType(),
                    'custom_view' => $group->isCustomizedView()
                );
            }
        }
    }
    if ($addOptions = $item->getOptionByCode('additional_options')) {
        $options = array_merge($options, unserialize($addOptions->getValue()));
    }
    return $options;
  } 

  private function SaveOrder($orderID) {
    // If you wanted to save any information, you would do it here. 
    // All I do here is save the now empty cart
    Mage::helper('checkout/cart')->getCart()->save();
  }
  
  private function RedirectMe($newURL) {
    Mage::app()->getFrontController()->getResponse()->setRedirect($newURL);
  }
}
