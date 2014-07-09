<?php
/**
 *  @copyright Copyright (c) 2014 DodatkiJoomla.pl
 *  @license GNU/GPL v2
 */
if (!defined('_VALID_MOS') && !defined('_JEXEC')) die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');


// jeżeli klasa vmPSPlugin nie istnieje, dołącz
if (!class_exists('vmPSPlugin'))
{
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}
	
class plgVmPaymentPayu extends vmPSPlugin 
{

    public static $_this = false;

	// konstruktor
    function __construct(& $subject, $config) 
	{
		// konstruktor kl. nadrzędnej
		parent::__construct($subject, $config);
		
		$this->_loggable = true;
		
	
		// to poniżej apisuje wartości z xml'a do kol. payment_params tabeli #__virtuemart_paymentmethods	
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush = array(
			'payu_pos_id' => array('', 'string'),
			'payu_md5_1' => array('', 'string'),
			'payu_md5_2' => array('', 'string'),
			'payu_pos_auth_key' => array('', 'string'),
			'payment_logos' => array('', 'string'),
			'payment_image' => array('', 'string'),
			'autoredirect' => array(1, 'int'),
			'payment_image' => array('', 'string'),
			'checkout_text' => array('', 'string'),
			'powiadomienia' => array(1, 'int'),
			'cost_per_transaction' => array(0, 'double'),
			'cost_percent_total' => array(0, 'double'),
			'tax_id' => array(0, 'int'),
			'url_pozytywny' => array('', 'string'),
			'url_negatywny' => array('', 'string'),
			'url_online' => array('', 'string'),
			'status_pending' => array('', 'string'),
			'status_success' => array('', 'string'),
			'status_canceled' => array('', 'string'),
			'auto_potwierdz' => array(1, 'int'),
	    );
		
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		
	}
	
	function getTableSQLFields() 
	{
		$SQLfields = array(
			'id' => ' int(11) UNSIGNED NOT NULL AUTO_INCREMENT ',
			'virtuemart_order_id' => ' int(11) UNSIGNED DEFAULT NULL',
			'order_number' => ' char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => ' mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'tax_id' => 'int(11) DEFAULT NULL',
			'payu_session_id' => 'varchar(32) ',
			'kwota_calkowita_PLN' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
			'payu_kwota_grosze' => 'int(11) DEFAULT NULL',
			'payu_ts' => 'varchar(32) '
			
		);
		return $SQLfields;
    }
	
	// potwierdzenie zamówienia funkcja 
	
	function plgVmPotwierdzeniePayU($cart, $order, $auto_redirect = false, $form_method = "GET")
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return null; // Inna metoda została wybrana, nie rób nic.
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}

		if (!class_exists('VirtueMartModelOrders'))
		{
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}
		
		// konwersja do PLN
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		
		$kwota_calkowita_PLN = round($paymentCurrency->convertCurrencyTo(114, $order['details']['BT']->order_total, false), 2); // konwertuj do PLN, 114 - id złotówki
	
		// zmienne		
		$kwota_grosze = round($kwota_calkowita_PLN*100,0);
		$zamowienie = $order['details']['BT'];
		$session_id = md5($zamowienie->order_number.'|'.time());
		$client_ip = $_SERVER['REMOTE_ADDR'];
		$ts = time();	
		// zmienne - kraj
		$q = 'SELECT country_2_code FROM #__virtuemart_countries WHERE virtuemart_country_id='.$zamowienie->virtuemart_country_id.' ';        // kraj
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $country = $db->loadResult();
		
				
		$this->_virtuemart_paymentmethod_id = $zamowienie->virtuemart_paymentmethod_id;
		$dbValues['order_number'] = $zamowienie->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbValues['kwota_calkowita_PLN'] = $kwota_calkowita_PLN;
		$dbValues['tax_id'] = $method->tax_id;
		
		// wartości PayU
		$dbValues['payu_session_id'] = $session_id;
		$dbValues['payu_kwota_grosze'] = $kwota_grosze;
		$dbValues['payu_ts'] = $ts;
		
		// zapisz do bazy
		$this->storePSPluginInternalData($dbValues);
		
		$dane_formularza = array();
		
		$dane_formularza['pos_id'] = $method->payu_pos_id;
		$dane_formularza['session_id'] = $session_id;
		$dane_formularza['pos_auth_key'] = $method->payu_pos_auth_key;
		$dane_formularza['amount'] = $kwota_grosze;
		$dane_formularza['desc'] = 'Zamówienie nr '.$zamowienie->order_number;
		$dane_formularza['order_id'] = $zamowienie->order_number;
		$dane_formularza['first_name'] = $zamowienie->first_name;
		$dane_formularza['last_name'] = $zamowienie->last_name;
		$dane_formularza['street'] = $zamowienie->address_1;
		$dane_formularza['city'] = $zamowienie->city;
		$dane_formularza['post_code'] = $zamowienie->zip;
		$dane_formularza['country'] = $country;
		$dane_formularza['email'] = $zamowienie->email;
		$dane_formularza['language'] = "pl";
		$dane_formularza['client_ip'] = $client_ip;
		$dane_formularza['ts'] = $ts;
		$dane_formularza['js'] = "0";
		
		/*
			sig payu:
			sig = md5 ( pos_id + pay_type + session_id + pos_auth_key + amount + desc + desc2 + trsDesc + order_id + first_name + last_name + street + street_hn + street_an + city + post_code + country + email + phone + language + client_ip + ts + key1 )		
		*/
		
		$sig = '';
		foreach( $dane_formularza as $pole_nazwa => $pole_wartosc )
		{
			if(  in_array( $pole_nazwa, array('pos_id','pay_type','session_id','pos_auth_key','amount','desc','desc2','trsDesc','order_id','first_name','last_name','street','street_hn','street_an','city','post_code','country','email','phone','language','client_ip','ts','key1') ) )
			{
				$sig .= $pole_wartosc;
			}
		}
		// key1
		$sig .= $method->payu_md5_1;
		$sig = md5( $sig );
		
		$dane_formularza['sig'] = $sig;
		
		// zawartośc HTML na podstronie potwierdzenia zamówienia //Numer zamówienia: '.$order['details']['BT']->order_number.'
		$html = '
		<div style="text-align: center; width: 100%; ">
		<form action="https://www.platnosci.pl/paygw/UTF/NewPayment" method="'.$form_method.'" class="form" name="platnosc_payu" id="platnosc_payu">';
		
		// Wyświetlanie hiddenów
		foreach( $dane_formularza as $pole_nazwa => $pole_wartosc )
		{
			$html .= '<input type="hidden" name="'.$pole_nazwa.'" value="'.$pole_wartosc.'">';
		}
			

		if(file_exists(JPATH_BASE.DS.'images'.DS.'stories'.DS.'virtuemart'.DS.'payment'.DS.$method->payment_image))
		{
			$pic = getimagesize(JURI::root().'images/stories/virtuemart/payment/'.$method->payment_image);	
			$html .= '		  
		  <input name="submit_send" value="" type="submit" style="border: 0; background: url(\''.JURI::root().'images/stories/virtuemart/payment/'.$method->payment_image.'\'); width: '.$pic[0].'px; height: '.$pic[1].'px; cursor: pointer;" /> ';
		}
		else
		{
			$html .= '<input name="submit_send" value="Zapłać z PayU" type="submit"  style="width: 110px; height: 45px;" /> ';
		}
		
		$html .= '	</form>		
		
		<script language=”JavaScript” type=”text/javascript”>
			document.forms[\'platnosc_payu\'].js.value=1;
		</script>
		
		<p style="text-align: center; width: 100%; ">'.$method->checkout_text.'</p>
		</div>
		';
		
		// automatyczne przerzucenie do płatności
		if($method->autoredirect && $auto_redirect)
		{
			$html .= '
			<script type="text/javascript">
				document.getElementById("platnosc_payu").submit();
			</script>';
		}
		
		
		return $html;
	}
	
	function plgVmConfirmedOrder($cart, $order)
	{
		// jeżeli nie zwraca $html - wyrzuc false
		if (!($html = $this->plgVmPotwierdzeniePayU($cart, $order, true))) {
			return false; 
		}
		
		// nazwa płatnosci - zmiana dla Joomla 2.5 !!!
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) 
		{
			return null;
		}
		$nazwa_platnosci = $this->renderPluginName($method);
		
		// tutaj w vm 2.0.2 trzeba dodać status na końcu, zeby się nie wywalało
		return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html, $nazwa_platnosci, $method->status_pending);
	}
	
	// zdarzenie po otrzymaniu poprawnego lub błędnego url'a z systemu payu
	function plgVmOnPaymentResponseReceived(&$html) 
	{
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; 
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		
	
		if(isset($_POST['pos_id']))
		{
			// dane z PayU
			$payment_data = $_POST;
			
			//potwierdzenie transakcji
			if(function_exists('curl_init') )
			{
				echo "OK";
				// walidacja
				if($payment_data['pos_id']==$method->payu_pos_id)
				{
					$db = &JFactory::getDBO();
					$q = 'SELECT payu.*, ord.order_status FROM '.$this->_tablename.' as payu JOIN `#__virtuemart_orders` as ord using(virtuemart_order_id) WHERE payu.payu_session_id="' .$payment_data['session_id']. '" ';		

					$db->setQuery($q);
					$payment_db = $db->loadObject();
					
					if(!empty($payment_db))
					{
						// sig'i
						$sig_online = $payment_data['sig'];
						$sig_db = md5 ( $method->payu_pos_id.$payment_db->payu_session_id.$payment_data['ts'].$method->payu_md5_2 );

						if($sig_online==$sig_db)
						{
							
							$url = "https://www.platnosci.pl/paygw/UTF/Payment/get/xml";							
							$ts = time();
							// sig = md5 ( pos_id + session_id + ts + key1 )
							$sig_get =  md5 ( $method->payu_pos_id.$payment_db->payu_session_id.$ts.$method->payu_md5_1 );
							$user_agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)"; 
							
							// post vars
							$P = array();
							$P[] = "pos_id=".$method->payu_pos_id;
							$P[] = "session_id=".$payment_db->payu_session_id;
							$P[] = "ts=".$ts;
							$P[] = "sig=".$sig_get;
									
							// curl
							$ch = curl_init(); 
							curl_setopt($ch, CURLOPT_POST,1); 
							if(count($P)) { curl_setopt($ch, CURLOPT_POSTFIELDS,join("&",$P)); } 
							curl_setopt($ch, CURLOPT_URL,$url); 
							curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  2); 
							curl_setopt($ch, CURLOPT_USERAGENT, $user_agent); 
							curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); 
							curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
							$result=curl_exec ($ch);


							// xml parse
							$xml = new SimpleXMLElement($result);
							$status = $xml->status;

							if($status=="OK")
							{
								switch($xml->trans->status)
								{
									case 99:
									// zmiań na status po potwierdzonej transakcji
									if($payment_db->order_status!="C" && $payment_db->order_status!='X')
									{
										// update statusu zamówienia
										$virtuemart_order_id = $payment_db->virtuemart_order_id;
										$message = 'Płatność została potwierdzona.';
										
										if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_success, $message, $method->powiadomienia))==false)
										{
											$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas zmiany statusu zamówienia na '.$method->status_success);
										}
										else
										{

										}
									}
									break;
									case 5:
									// zmiań status zamówienia dla transakcji oczekujących na odbiór
									if($payment_db->order_status!="C" && $payment_db->order_status!='X')
									{
										if($method->auto_potwierdz)
										{
											$url = "https://www.platnosci.pl/paygw/UTF/Payment/confirm/xml";
											// curl
											$ch = curl_init(); 
											curl_setopt($ch, CURLOPT_POST,1); 
											if(count($P)) { curl_setopt($ch, CURLOPT_POSTFIELDS,join("&",$P)); } 
											curl_setopt($ch, CURLOPT_URL,$url); 
											curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  2); 
											curl_setopt($ch, CURLOPT_USERAGENT, $user_agent); 
											curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); 
											curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
											$result=curl_exec ($ch);
										}
									}
									break;
									case 2:
									// zmiań status zamówienia dla transakcji anulowanej
									if($payment_db->order_status!="C" && $payment_db->order_status!='X')
									{
										// update statusu zamówienia
										$virtuemart_order_id = $payment_db->virtuemart_order_id;
										$message = 'Płatność została anulowana.';
										
										if(($status = $this->nowyStatus($virtuemart_order_id,$method->status_canceled, $message, $method->powiadomienia))==false)
										{
											$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas zmiany statusu zamówienia na '.$method->status_canceled);
										}
										else
										{

										}
									}
									break;
								}
							}
							else
							{
								$err_msg = "Nieznany błąd";
								if($this->getError((string)$xml->error->nr))
								{
									$err_msg = $this->getError((string)$xml->error->nr);
								}
								$this->logInfo('plgVmOnPaymentResponseReceived Bład podczas pobierania inf. nt. płatności - '.$err_msg);
							}
						}
						else
						{
							$this->logInfo('plgVmOnPaymentResponseReceived sig z posta nie jest identyczny z sigiem z bazy');
						}
					}
					else
					{
						$this->logInfo('plgVmOnPaymentResponseReceived Pusty rekord pobierania informacji nt. zamówienia z bazy danych');
					}
				}
				else
				{
					$this->logInfo('plgVmOnPaymentResponseReceived Błędny pos_id');
				}
			}
			else
			{
				$this->logInfo('plgVmOnPaymentResponseReceived brak CURLa');
			}
			
			// zakończenie skryptu, żeby templata się nie zaciągała
			exit();
		}
		else if(isset($_GET['error']) )
		{
			// negatywna
			$err_msg = "Nieznany błąd";
			if($this->getError($_GET['error']))
			{
				$err_msg = $this->getError($_GET['error']);
			}
			JError::raiseWarning( 100, '<b>Wystąpił błąd:</b> '.$err_msg);
			return true;
		}
		else
		{
			// pozytywna
			JFactory::getApplication()->enqueueMessage( 'Dziękujemy za dokonanie transakcji za pośrednictwem PayU.' );	
			return true;
		}
		
	}

	
	// Kody błędów w PayU	
	function getError($kod_bledu)
	{
		$error = array();
		$error[100] = "brak lub błędna wartość parametru pos_id";
		$error[101] = "brak parametru session_id";
		$error[102] = "brak parametru ts";
		$error[103] = "brak lub błędna wartość parametru sig";
		$error[104] = "brak parametru desc";
		$error[105] = "brak parametru client_ip";
		$error[106] = "brak parametru first_name";
		$error[107] = "brak parametru last_name";
		$error[108] = "brak parametru street";
		$error[109] = "brak parametru city";
		$error[110] = "brak parametru post_code";
		$error[111] = "brak parametru amount (lub/oraz amount_netto dla usługi SMS)";
		$error[112] = "błędny numer konta bankowego";
		$error[113] = "brak parametru email";
		$error[114] = "brak numeru telefonu";
		$error[200] = "inny chwilowy błąd";
		$error[201] = "inny chwilowy błąd bazy danych";
		$error[202] = "POS o podanym identyfikatorze jest zablokowany";
		$error[203] = "niedozwolona wartość pay_type dla danego pos_id";
		$error[204] = "podana metoda płatności (wartość pay_type) jest chwilowo zablokowana dla danego pos_id, np. przerwa konserwacyjna bramki płatniczej";
		$error[205] = "kwota transakcji mniejsza od wartości minimalnej";
		$error[206] = "kwota transakcji większa od wartości maksymalnej";
		$error[207] = "przekroczona wartość wszystkich transakcji dla jednego klienta w ostatnim przedziale czasowym";
		$error[208] = "POS działa w wariancie ExpressPayment lecz nie nastąpiła aktywacja tego wariantu współpracy(czekamy na zgodę działu obsługi klienta)";
		$error[209] = "błędny numer pos_id lub pos_auth_key";
		$error[500] = "transakcja nie istnieje";
		$error[501] = "brak autoryzacji dla danej transakcji";
		$error[502] = "transakcja rozpoczęta wcześniej";
		$error[503] = "autoryzacja do transakcji była już przeprowadzana";
		$error[504] = "transakcja anulowana wcześniej";
		$error[505] = "transakcja przekazana do odbioru wcześniej";
		$error[506] = "transakcja już odebrana";
		$error[507] = "błąd podczas zwrotu środków do klienta";
		$error[599] = "błędny stan transakcji, np. nie można uznać transakcji kilka razy lub inny, prosimy o kontakt";
		$error[999] = "inny błąd krytyczny - prosimy o kontakt";

		if(array_key_exists($kod_bledu, $error))
		{	
			return $error[$kod_bledu];
		}
		else
		{
			return false;
		}
	}
	
	// wyświetl dane płatności dla zamówienia (backend)
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) 
	{
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
		$html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', round($paymentTable->kwota_calkowita_PLN, 2).' PLN');
		$html .= '</table>' . "\n";
		return $html;
    }
	
	
	// moja funkcja nowego statusu
	function nowyStatus($virtuemart_order_id, $nowy_status, $notatka = "",  $wyslij_powiadomienie=1)
	{
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			
			// załadowanie języka dla templatey zmiany statusu zam. z admina!
			$lang = &JFactory::getLanguage();		
			$lang->load('com_virtuemart',JPATH_ADMINISTRATOR);
			
			$modelOrder = VmModel::getModel('orders');
			$zamowienie = $modelOrder->getOrder($virtuemart_order_id);
			if(empty($zamowienie))
			{
				return false;
			}
			
			$order['order_status'] = $nowy_status;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['customer_notified'] = $wyslij_powiadomienie;
			$order['comments'] = $notatka;
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

			// last modify + lock płatności w BD
			
			$db = &JFactory::getDBO();
			// sql'e zależne od nowego statusu
			
			if($nowy_status=="C" || $nowy_status=="X")
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW(), locked_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';		
			}
			else
			{
				$q = 'UPDATE '.$this->_tablename.' SET modified_on=NOW() WHERE virtuemart_order_id='.$virtuemart_order_id.';   ';
			}

			$db->setQuery($q);
			$wynik = $db->query($q);
			
			if(empty($wynik))
			{
				return false;
			}

			$message = 'Status zamówienia zmienił się.';


			
			return $message;
	}
	
	
	// sprawdź czy płatność spełnia wymagania
	protected function checkConditions($cart, $method, $cart_prices) 
	{
		return true;
	}
	
	
	/*
	*
	*	RESZTA METOD
	*
	*/
	
	
	protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment PayU Table');
    }
	
	// utwórz opcjonalnie tabelę płatności, zapisz dane z xml'a itp.
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) 
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
	
	// zdarzenie po wyborze płatności (front)
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) 
	{
		return $this->OnSelectCheck($cart);
    }
		
	// zdarzenie wywoływane podczas listowania płatności
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) 
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
	
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) 
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) 
	{
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		 $this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }
	
	// sprawdza ile pluginów płatności jest dostepnych, jeśli tylko jeden, użytkownik nie ma wyboru
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) 
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
	
	// zdarzenie wywoływane podczas przeglądania szczegółów zamówienia (front)
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) 
	{	
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
	
	 // funkcja wywołująca stricte zawartość komórki payment w szczegółach zamówienia (front - konto usera)
	 function onShowOrderFE($virtuemart_order_id, $virtuemart_method_id, &$method_info)
	 {
	 	if (!($this->selectedThisByMethodId($virtuemart_method_id))) {
			return null;
		}
		
		//  ograniczenie generowania się dodatkowego fomrularza, jeśli klient nie opłacił jeszcze zamówienia, tylko do szczegółów produktu
		// dodatkowo w zależności od serwera, tworzenie faktury w PDF głupieje czasami przy obrazkach dla płatności 
		if(isset($_REQUEST['view']) && $_REQUEST['view']=='orders' && isset($_REQUEST['layout']) && $_REQUEST['layout']=='details')
		{
			// wywołaj cały formularz
			if (!class_exists('VirtueMartModelOrders'))
			{
				require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			}
			if (!class_exists('VirtueMartCart'))
			{
				require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
			}	
			if (!class_exists('CurrencyDisplay'))
			{
				require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
			}
			$modelOrder = new VirtueMartModelOrders();
			$cart = VirtueMartCart::getCart();
			$order = $modelOrder->getOrder($virtuemart_order_id);
			
			if (!($html = $this->plgVmPotwierdzeniePayU($cart, $order, false ,"POST")) || $order['details']['BT']->order_status=='C' || $order['details']['BT']->order_status=='U' ) 
			{			
				$method_info = $this->getOrderMethodNamebyOrderId($virtuemart_order_id);
			}
			else
			{
				$method_info = $html;
			}
		}
		else
		{
			$method_info = 'PayU';
		}
	 }
	 
	 // pobranie loga z koszyka, wyboru płątności, kroków zamówienia
	function getOrderMethodNamebyOrderId ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id.  ' ORDER BY id DESC LIMIT 1 ';
		$db->setQuery ($q);
		if (!($pluginInfo = $db->loadObject ())) {
			vmWarn ('Attention, ' . $this->_tablename . ' has not any entry for the order ' . $db->getErrorMsg ());
			return NULL;
		}
		$idName = $this->_psType . '_name';

		return $pluginInfo->$idName;
	}
	
	 /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */

	// wymagane aby zapis XML'a do BD działał
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	return $this->onShowOrderPrint($order_number, $method_id);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	
		// nadpisujemy parametr , aby edycja nic mu nie robiła!
		$virtuemart_paymentmethod_id = $_GET['cid'][0];
		$url_pozytywny = 'url_pozytywny="'.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$virtuemart_paymentmethod_id.'"|';			
		$url_negatywny = 'url_negatywny="'.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$virtuemart_paymentmethod_id.'&error=%error%"|';
		$url_online = 'url_online="'.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='.$virtuemart_paymentmethod_id.'"|';	
		$data->payment_params .= $url_pozytywny;
		$data->payment_params .= $url_negatywny;
		$data->payment_params .= $url_online;
		
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

	// wymagane aby zapis XML'a do BD działał
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	return $this->setOnTablePluginParams($name, $id, $table);
    }
}