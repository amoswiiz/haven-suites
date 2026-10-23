<?php

namespace App\Controllers;

use App\Models\PropertyModel;
use CodeIgniter\Exceptions\PageNotFoundException;

class Properties extends BaseController
{
    protected $properties;

    public function __construct()
    {
        $this->properties = new PropertyModel();
    }

public function index()
{
    $model = new \App\Models\PropertyModel();

    return view('properties/index', [
        'properties' => $model
            ->orderBy('name', 'ASC')
            ->findAll()
    ]);
}


    public function create()
    {
        return view('properties/form', [
            'property' => null,
            'errors' => [],
        ]);
    }

    public function store()
    {
        $data = $this->getFormData();

        if (!$this->properties->insert($data)) {
            return view('properties/form', [
                'property' => null,
                'errors' => $this->properties->errors(),
            ]);
        }

        return redirect()->to('/properties')
            ->with('success', 'Property added successfully.');
    }

    public function edit($id)
    {
        return view('properties/form', [
            'property' => $this->findProperty($id),
            'errors' => [],
        ]);
    }

    public function update($id)
    {
        $property = $this->findProperty($id);
        $data = $this->getFormData();

        if (!$this->properties->update($property['id'], $data)) {
            return view('properties/form', [
                'property' => $property,
                'errors' => $this->properties->errors(),
            ]);
        }

        return redirect()->to('/properties')
            ->with('success', 'Property updated successfully.');
    }

    public function delete($id)
    {
        $property = $this->findProperty($id);
        $this->properties->delete($property['id']);

        return redirect()->to('/properties')
            ->with('success', 'Property deleted successfully.');
    }

    private function findProperty($id)
    {
        $property = $this->properties->find($id);

        if (!$property) {
            throw PageNotFoundException::forPageNotFound(
                'Property not found.'
            );
        }

        return $property;
    }

    private function getFormData()
    {
        return [
            'name' => trim((string) $this->request->getPost('name')),
            'location' => trim((string) $this->request->getPost('location')),
            'address' => trim((string) $this->request->getPost('address')),
            'description' => trim((string) $this->request->getPost('description')),
            'status' => $this->request->getPost('status'),
        ];
    }
}
