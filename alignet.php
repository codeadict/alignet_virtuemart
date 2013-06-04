<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
    
/*
 * Plugin Virtuemart para pagos con Alignet VPOS(www.alignet.com)
 * @author Dairon Medina Caro <dairon.medina@gmail.com>
 * @name Alignet Payments
 * @package VirtueMart
 * @subpackage payment
 * @version 1.0.0
 * @website http://codeadict.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see licence.txt
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

include('libs/vpos_plugin.php');
 
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
    
class plgVmPaymentAlignet extends vmPSPlugin {

    public static $_this = false;
    
    //Constructor de la clase
    function __construct(& $subject, $config) {
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
    }
    
    //Crear la tabla SQL para este plugin
    protected function getVmPluginCreateTableSQL() {
        return $this->createTableSQL('Payment Alignet Table');
    }
    
    /**
     * Campos para crear la tabla de pagos
     * @return string Campos SQL
     */
    function getTableSQLFields() {
    	$SQLfields = array(
    			'id' => 'bigint(1) unsigned NOT NULL AUTO_INCREMENT',
    			'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
    			'order_number' => 'char(32) DEFAULT NULL',
    			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
    			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
    			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
    			'payment_currency' => 'char(3) ',
    			'tax_id' => 'smallint(11) DEFAULT NULL'
    	);
    
    	return $SQLfields;
    }
    
    function getPluginParams(){
    	$db = JFactory::getDbo();
    	$sql = "select virtuemart_paymentmethod_id from #__virtuemart_paymentmethods where payment_element = 'alignet'";
    	$db->setQuery($sql);
    	$id = (int)$db->loadResult();
    	return $this->getVmPluginMethod($id);
    }
    
	//     Crea una orden en estado Confirmado
    function plgVmConfirmedOrder($cart, $order) {
    
    	if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
    		return null; // Another method was selected, do nothing
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
    		return false;
    	}
    
    	$lang = JFactory::getLanguage();
    	$filename = 'com_virtuemart';
    	$lang->load($filename, JPATH_ADMINISTRATOR);
    	$vendorId = 0;
    
    	$html = "";
    
    	if (!class_exists('VirtueMartModelOrders'))
    		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
    	$this->getPaymentCurrency($method);
    	
    	// Obtener moneda
    	$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
    	$db = &JFactory::getDBO();
    	$db->setQuery($q);
    	$currency_code_3 = $db->loadResult();
    	$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
    	
    	//Obtener precio Total en la moneda Seleccionada
    	$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
    	$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
    
    	$this->_virtuemart_paymentmethod_id = $order['details']['BT']->virtuemart_paymentmethod_id;
    	
    	$dbValues['payment_name'] = $this->renderPluginName($method);
    	$dbValues['order_number'] = $order['details']['BT']->order_number;
    	$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
    	$dbValues['payment_currency'] = $currency_code_3;
    	$dbValues['payment_order_total'] = $totalInPaymentCurrency;
    	$dbValues['tax_id'] = $method->tax_id;
    	$this->storePSPluginInternalData($dbValues);
    
    	//Retorna HTML para Pago
    	$html = $this->retornaHtmlPago( $order, $method, 1);
    
    	JFactory::getApplication()->enqueueMessage(utf8_encode(
    		"Su pedido fue realizado correctamente. Usted será redireccionado para efectuar el pago de su compra."
    	));
    
    	$novo_status = $method->status_aguardando;
    	
    	return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html, $dbValues['payment_name'], $novo_status);
    
    }
    
    
    function retornaHtmlPago( $order, $method, $redir ) {
    	$lang = JFactory::getLanguage();
    	$filename = 'com_virtuemart';
    	$lang->load($filename, JPATH_ADMINISTRATOR);
    	$vendorId = 0;
    
    	$html = '<table>' . "\n";
    	$html .= $this->getHtmlRow('STANDARD_PAYMENT_INFO', $dbValues['payment_name']);
    	if (!empty($payment_info)) {
    		$lang = & JFactory::getLanguage();
    		if ($lang->hasKey($method->payment_info)) {
    			$payment_info = JTExt::_($method->payment_info);
    		} else {
    			$payment_info = $method->payment_info;
    		}
    		$html .= $this->getHtmlRow('STANDARD_PAYMENTINFO', $payment_info);
    	}
    	if (!class_exists('CurrencyDisplay'))
    		require( JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php' );
    	$currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);
    	$html .= $this->getHtmlRow('STANDARD_ORDER_NUMBER', $order['details']['BT']->order_number);
    	$html .= $this->getHtmlRow('STANDARD_AMOUNT', $currency->priceDisplay($order['details']['BT']->order_total));
    	$html .= '</table>' . "\n";
    	
    	//Enviar Arreglo al VPOS con datos de la transaccion
    	$array_send['acquirerId'] = $method->codigo_adquiriente;
    	$array_send['commerceId'] = $method->codigo_comercio;
    	$array_send['purchaseAmount ']= $order['details']['ST']->TotalPrice;
    	$array_send['purchaseCurrencyCode'] = $method->moneda_comercio;
    	$array_send['purchaseOperationNumber']= $order["details"]["ST"]->order_number!=''?$order["details"]["ST"]->order_number:$order["details"]["BT"]->order_number;
    	$array_send['billingAddress'] = ($order["details"]["ST"]->address_1!=''?$order["details"]["ST"]->address_1:$order["details"]["BT"]->address_1) . ' ' . ($order["details"]["ST"]->address_2!=''?$order["details"]["ST"]->address_2:$order["details"]["BT"]->address_2);
    	$array_send['billingCity'] = $order["details"]["ST"]->city!=''?$order["details"]["ST"]->city:$order["details"]["BT"]->city;
    	$array_send['billingState'] = $estadoCobranza;
    	
    	//Agregar Provincias
    	$cod_estado = (!empty($order["details"]["ST"]->virtuemart_state_id)?$order["details"]["ST"]->virtuemart_state_id:$order["details"]["BT"]->virtuemart_state_id);
    	$estado = ShopFunctions::getStateByID($cod_estado, "state_3_code");
    	
    	$array_send['billingCountry'] = $estado;
    	$array_send['billingZIP'] = $order["details"]["ST"]->zip!=''?$order["details"]["ST"]->zip:$order["details"]["BT"]->zip;
    	$array_send['billingPhone'] = $order["details"]["ST"]->phone_1!=''?$order["details"]["ST"]->phone_1:$order["details"]["BT"]->phone_1;
    	$array_send['billingEMail'] = $order["details"]["ST"]->email!=''?$order["details"]["ST"]->email:$order["details"]["BT"]->email; 
    		
    	$array_send['billingFirstName'] = $order["details"]["ST"]->first_name!=''?$order["details"]["ST"]->first_name:$order["details"]["BT"]->first_name;
    	$array_send['billingLastName'] = $order["details"]["ST"]->last_name!=''?$order["details"]["ST"]->last_name:$order["details"]["BT"]->last_name;
    	$array_send['language']= $method->idioma_comercio;
    	
    	//Arreglo que devuelve el VPOS
    	$array_get['XMLREQ'] = "";
    	$array_get['DIGITALSIGN'] = "";
    	$array_get['SESSIONKEY'] = "";
    	
    	//Enviar datos al VPOS
    	VPOSSend($array_send, $array_get, $llave_pub_crypto_alignet, $llave_priv_firma_alignet, $method->vi);
    	
    	$action = ''
    	
    	if ($method->modo_alignet == 0 ){
    	//URL de Pruebas
    	$action = 'https://servicios.alignet.com/VPOS/MM/transactionStart20.do';
    	} else {	
    	//URL de Produccion
    	$action = 'https://vpayment.verifika.com/VPOS/MM/transactionStart20.do';
    	}
    	
    	//HTML con formulario que envia al pago
    	$html .= '<form id="frm_alignet_vpos" action="' . $action . '" method="post" >';
    	$html .= '  <input type="hidden" name="IDACQUIRER" value="' . $method->codigo_adquiriente . '"  />
                    <input type="hidden" name="IDCOMMERCE" value="' . $method->codigo_comercio . '"  />
                    <input type="hidden" name="XMLREQ" value="' . $array_get['XMLREQ'] . '"  />
                    <input type="hidden" name="DIGITALSIGN" value="' . $array_get['DIGITALSIGN'] . '"  />
                    <input type="hidden" name="SESSIONKEY" value="' . $array_get['SESSIONKEY'] . '"  /> ';
    
    	// desconto do pedido
    	$order_discount = (float)$order["details"]["BT"]->order_discount;
    	if (empty($order_discount) && (!empty($order["details"]["BT"]->coupon_discount))) {
    		$order_discount = (float)$order["details"]["BT"]->coupon_discount;
    	}
    	$order_discount = (-1)*abs($order_discount);
    	if (!empty($order_discount)) {
    	$html .= '<input type="hidden" name="extras" value="'.number_format($order_discount, 2, ",", "").'" />';
		}
    			//var_dump($order_discount); die();
    			//var_dump($order["details"]["BT"]->order_discount); die();
    			$order_subtotal = $order['details']['BT']->order_subtotal;
    
    			if(!class_exists('VirtueMartModelCustomfields'))require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'customfields.php');
    
		foreach ($order['items'] as $p) {
    				$i++;
    				$valor_produto = $p->product_final_price;
    				// desconto do pedido
    				/*
    				if ($order_discount != 0) {
    				$valor_item = $valor_produto - (($valor_produto/$order_subtotal) * $order_discount);
    				} else {
    				}
    				*/
    				$valor_item = $valor_produto;
    
    
    				$product_attribute = strip_tags(VirtueMartModelCustomfields::CustomsFieldOrderDisplay($p,'FE'));
    				$html .='<input type="hidden" name="item_id_' . $i . '" value="' . $p->virtuemart_order_item_id . '">
				<input type="hidden" name="item_descr_' . $i . '" value="' . $p->order_item_name . '">
				<input type="hidden" name="item_quant_' . $i . '" value="' . $p->product_quantity . '">
				<input type="hidden" name="item_valor_' . $i . '" value="' . number_format($p->product_final_price, 2, ",", "") .'">
    				<input type="hidden" name="item_peso_' . $i . '" value="' . ShopFunctions::convertWeigthUnit($p->product_weight, $p->product_weight_uom, "GR") . '">';
    				}
    
    				$url 	= JURI::root();
    				$url_lib 			= $url.DS.'plugins'.DS.'vmpayment'.DS.'alignet'.DS;
    				$url_imagem_pagamento 	= $url_lib . 'imagenes'.DS.'alignet.gif';
    
    				// segundos para redirecionar al VPOS
    				if ($redir) {
    				// segundos para redirecionar al VPOS
    				$segundos = $method->segundos_redirecionar;
    					$html .= '<br/><br/>Usted ser&aacute; redireccionado a efectuar el pago en '.$segundos.' segundo(s), si no desea esperar haga click en el logo de abajo:<br />';
    					$html .= '<script>setTimeout(\'document.getElementById("frm_alignet_vpos").submit();\','.$segundos.'000);</script>';
		}
		$html .= '<div align="center"><br /><input type="image" value="Haga Click para efectuar el pago" class="button" src="'.$url_imagen_vpos.'" /></div>';
        $html .= '</form>';
		
		//Devuelve el HTML
        return $html;
	}
	
	/**
	 * Mostrar datos guardados de pago para una orden
	 *
	 */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
		if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
			return null; // Another method was selected, do nothing
		}
	
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
				. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
			vmWarn(500, $q . " " . $db->getErrorMsg());
			return '';
		}
		$this->getPaymentCurrency($paymentTable);
	
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
		$html .= '</table>' . "\n";
		return $html;
	}
	
	//TODO: Implementar para joomla 1.7
	
	/**
	 * Crea la tabla para este plugin si no existe.
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}
	
	
	/**
	 * Este evento se lanza despues que se ha seleccionado un método de pago. Se puede usar para guardar 
	 * informacion extra sobre el pago en el carrito.
	 *
	 * @param VirtueMartCart $cart: El Carrito Actual
	 * @return null si no se ha seleccionado el pago, true si los datos son validos o mensaje de error si son invalidos
	 *
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
	}
	
	
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
	}
	
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}
	
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
	
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);
	
		$paymentCurrencyId = $method->payment_currency;
	}
	
	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checka cuantos plugins de pagos hay disponibles. Si solo hay uno no se muestran opciones para escoger
	 * @param VirtueMartCart cart: el obhjeto del carrito
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 *
	 */
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
	}
	
	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 */
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$orderModel = VmModel::getModel('orders');
		$orderDetails = $orderModel->getOrder($virtuemart_order_id);
		if (!($method = $this->getVmPluginMethod($orderDetails['details']['BT']->virtuemart_paymentmethod_id))) {
			return false;
		}
	
		$view = JRequest::getVar('view');
		// si esta en estado pendiente la orden
		if ($method->status_aguardando == $orderDetails['details']['BT']->order_status and $view == 'orders' and $orderDetails['details']['BT']->virtuemart_paymentmethod_id == $virtuemart_paymentmethod_id) {
			JFactory::getApplication()->enqueueMessage(utf8_encode(
			"El pago de este pedido consta como Pendiente de pago aun. Click para ser redirreccionado a la pasarela de pago donde podrá cancelar el monto de su orden.")
			);
				
			$redir = 0;
			$html = $this->retornaHtmlPagamento( $orderDetails, $method, $redir );
			echo $html;
		}
	
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}
	
	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 */
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}
	
	function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
	}
	
	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}
	
	//TODO: Terminar esta funcion para Alignet
	
	/**
	 * This event is fired when the  method notifies you when an event occurs that affects the order.
	 * Typically,  the events  represents for payment authorizations, Fraud Management Filter actions and other actions,
	 * such as refunds, disputes, and chargebacks.
	 *
	 * NOTE for Plugin developers:
	 *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
	 *
	 * @param $return_context: it was given and sent in the payment form. The notification should return it back.
	 * Used to know which cart should be emptied, in case it is still in the session.
	 * @param int $virtuemart_order_id : payment  order id
	 * @param char $new_status : new_status for this order id.
	 * @return mixed Null when this method was not selected, otherwise the true or false
	 *
	 *
	 *
	 public function plgVmOnPaymentNotification() {
	 return null;
	 }
	 */
	function plgVmOnPaymentNotification() {
	
		header("Status: 200 OK");
		if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		$pagseguro_data = $_REQUEST;
	
		if (!isset($pagseguro_data['TransacaoID'])) {
			return;
		}
		$order_number = $pagseguro_data['Referencia'];
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		//$this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id  found ' . $virtuemart_order_id, 'message');
	
		if (!$virtuemart_order_id) {
			return;
		}
		$vendorId = 0;
		$payment = $this->getDataByOrderId($virtuemart_order_id);
		if($payment->payment_name == '') {
			return false;
		}
		$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		//$this->_debug = $method->debug;
		if (!$payment) {
			$this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');
			return null;
		}
		$this->logInfo('pagseguro_data ' . implode('   ', $pagseguro_data), 'message');
	
		// get all know columns of the table
		$db = JFactory::getDBO();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery($query);
		$columns = $db->loadResultArray(0);
		$post_msg = '';
		foreach ($pagseguro_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			$table_key = 'pagseguro_response_' . $key;
			if (in_array($table_key, $columns)) {
				$response_fields[$table_key] = $value;
			}
		}
	
		$response_fields['payment_name'] = $payment->payment_name;
		$response_fields['order_number'] = $order_number;
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
	
		if (empty($pagseguro_data['cod_status']) || ($pagseguro_data['cod_status'] != '0' && $pagseguro_data['cod_status'] != '1' && $pagseguro_data['cod_status'] != '2')) {
			//return false;
		}
			
		$pagseguro_status = $pagseguro_data['StatusTransacao'];
		switch($pagseguro_status){
			case 'Completo': 	$new_status = $method->status_completo; break;
			case 'Aprovado': 	$new_status = $method->status_aprovado; break;
			case 'Em Análise': 	$new_status = $method->status_analise; break;
			case 'Cancelado': 	$new_status = $method->status_cancelado; break;
			case 'Paga': 		$new_status = $method->status_paga; break;
			case 'Disponivel': 	$new_status = $method->status_disponivel; break;
			case 'Devolvida': 	$new_status = $method->status_devolvida; break;
			case 'Aguardando Pagto':
			default: $new_status = $method->status_aguardando; break;
		}
	
	
		$this->logInfo('plgVmOnPaymentNotification return new_status:' . $new_status, 'message');
	
		if ($virtuemart_order_id) {
			// send the email only if payment has been accepted
			if (!class_exists('VirtueMartModelOrders'))
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			$modelOrder = new VirtueMartModelOrders();
			$orderitems = $modelOrder->getOrder($virtuemart_order_id);
			$nb_history = count($orderitems['history']);
			$order['order_status'] = $new_status;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['comments'] = 'O status do seu pedido '.$order_number.' no Pagseguro foi atualizado: '.utf8_encode($pagseguro_data['StatusTransacao']);
			if ($nb_history == 1) {
				$order['comments'] .= "<br />" . JText::sprintf('VMPAYMENT_PAYPAL_EMAIL_SENT');
				$order['customer_notified'] = 0;
			} else {
				$order['customer_notified'] = 1;
			}
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			if ($nb_history == 1) {
				if (!class_exists('shopFunctionsF'))
					require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
				shopFunctionsF::sentOrderConfirmedEmail($orderitems);
				$this->logInfo('Notification, sentOrderConfirmedEmail ' . $order_number. ' '. $new_status, 'message');
			}
		}
		//Limpiar el Carrito
		$this->emptyCart($return_context);
	}
	
	
	/**
	 * plgVmOnPaymentResponseReceived
	 * This event is fired when the  method returns to the shop after the transaction
	 *
	 *  the method itself should send in the URL the parameters needed
	 * NOTE for Plugin developers:
	 *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
	 *
	 * @param int $virtuemart_order_id : should return the virtuemart_order_id
	 * @param text $html: the html to display
	 * @return mixed Null when this method was not selected, otherwise the true or false
	 *
	 *
	 *
	 function plgVmOnPaymentResponseReceived(, &$virtuemart_order_id, &$html) {
	 return null;
	 }
	 */
	function plgVmOnPaymentResponseReceived(&$html) {
	
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
	
		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // No hacer nada, fue seleccionado oto metodo de pago
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!class_exists('VirtueMartCart'))
			require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		$payment_data = JRequest::get('post');
		$payment_name = $this->renderPluginName($method);
		$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);
	
		if (!empty($payment_data)) {
			vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
			$order_number = $payment_data['invoice'];
			$return_context = $payment_data['custom'];
			if (!class_exists('VirtueMartModelOrders'))
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
			$payment_name = $this->renderPluginName($method);
			$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);
	
			if ($virtuemart_order_id) {
	
				//  Enviar el correo solo si se acepto el pago
				if (!class_exists('VirtueMartModelOrders'))
					require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
				$modelOrder = new VirtueMartModelOrders();
				$orderitems = $modelOrder->getOrder($virtuemart_order_id);
				$nb_history = count($orderitems['history']);

				if (!class_exists('shopFunctionsF'))
					require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
				if ($nb_history == 1) {
					if (!class_exists('shopFunctionsF'))
						require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
					shopFunctionsF::sentOrderConfirmedEmail($orderitems);
					$this->logInfo('plgVmOnPaymentResponseReceived, sentOrderConfirmedEmail ' . $order_number, 'message');
					$order['order_status'] = $orderitems['items'][$nb_history - 1]->order_status;
					$order['virtuemart_order_id'] = $virtuemart_order_id;
					$order['customer_notified'] = 0;
					$order['comments'] = JText::sprintf('VMPAYMENT_PAYPAL_EMAIL_SENT');
					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
				}
			}
		}

		// Obtener el carrito actual y limpiarle
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return true;
	}
	
	
	
	
 

} //Fin de la Clase
