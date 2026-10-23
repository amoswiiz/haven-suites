<?php
namespace App\Http\Controllers\Haven;
use App\Support\Migration\Request;
use App\Support\Migration\Validation;

abstract class BaseController extends \Illuminate\Routing\Controller
{
    protected Request $request;
    protected \Illuminate\Http\Response $response;
    protected Validation $validator;
    public function callAction($method, $parameters) {
        $this->request = new Request();
        $this->response = response('');
        $this->validator = new Validation();
        // Tenant slug is routing context, not a business method argument.
        unset($parameters['tenant']);
        return parent::callAction($method, $parameters);
    }
    protected function validate(array $rules): bool {
        return $this->validator->setRules($rules)->run(request()->all());
    }
}
