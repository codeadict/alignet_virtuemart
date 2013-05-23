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
 

} //Fin de la Clase
 
 ?>
