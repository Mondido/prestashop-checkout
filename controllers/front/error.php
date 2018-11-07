<?php

class MondidocheckoutErrorModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;
    public $display_column_right = false;
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign(array(
            'message' => $this->module->l('An error occurred on the server. Please try to place the order again.')
        ));

        return $this->setTemplate('error.tpl');
    }
}
