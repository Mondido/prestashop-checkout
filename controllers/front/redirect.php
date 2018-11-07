<?php

class MondidocheckoutRedirectModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;
    public $display_column_right = false;
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign(array(
            'this_path' => $this->module->getPath(),
            'goto' => Tools::getValue('goto'),
        ));

        //return $this->setTemplate('redirect.tpl');

        echo $this->context->smarty->display(_PS_ROOT_DIR_ . '/modules/mondidocheckout/views/templates/front/redirect.tpl');
        exit();
    }
}
