<?php

namespace App\Http\Controllers\Haven;

use App\Models\Haven\UnitModel;
use App\Models\Haven\PropertyModel;
use App\Support\Migration\PageNotFoundException;

class Units extends BaseController
{
    /**
     * Display all apartment units.
     */
    public function index()
    {
        $unitModel = new UnitModel();

        $units = $unitModel
            ->select('units.*, properties.name AS property_name')
            ->join(
                'properties',
                'properties.id = units.property_id',
                'left'
            )
            ->orderBy('properties.name', 'ASC')
            ->orderBy('units.unit_number', 'ASC')
            ->findAll();

        return haven_view('units/index', [
            'units' => $units
        ]);
    }

    /**
     * Show the Add Apartment form.
     */
    public function create()
    {
        $propertyModel = new PropertyModel();

        return haven_view('units/form', [
            'unit' => null,
            'properties' => $propertyModel
                ->orderBy('name', 'ASC')
                ->findAll()
        ]);
    }

    /**
     * Save a new apartment.
     */
    public function store()
    {
        return $this->saveUnit(null);
    }

    /**
     * Show the Edit Apartment form.
     */
    public function edit($id)
    {
        $unitModel = new UnitModel();
        $propertyModel = new PropertyModel();

        $unit = $unitModel->find($id);

        if (!$unit) {
            throw PageNotFoundException::forPageNotFound(
                'Apartment not found.'
            );
        }

        return haven_view('units/form', [
            'unit' => $unit,
            'properties' => $propertyModel
                ->orderBy('name', 'ASC')
                ->findAll()
        ]);
    }

    /**
     * Update an existing apartment.
     */
    public function update($id)
    {
        return $this->saveUnit((int) $id);
    }

    /**
     * Handle both creating and updating apartments.
     */
    private function saveUnit(?int $id)
    {
        $unitModel = new UnitModel();
        $propertyModel = new PropertyModel();

        // Confirm apartment exists when editing.
        if ($id !== null && !$unitModel->find($id)) {
            throw PageNotFoundException::forPageNotFound(
                'Apartment not found.'
            );
        }

        $propertySelection = trim((string) $this->request->getPost('property_id'));
        $creatingBuilding = $propertySelection === 'new';
        $propertyId = $creatingBuilding ? null : filter_var($propertySelection, FILTER_VALIDATE_INT);
        $buildingName = trim((string) $this->request->getPost('building_name'));
        $buildingLocation = trim((string) $this->request->getPost('building_location'));

        if ($creatingBuilding) {
            if ($buildingName === '' || mb_strlen($buildingName) > 150 ||
                $buildingLocation === '' || mb_strlen($buildingLocation) > 150) {
                return redirect()->back()->withInput()
                    ->with('error', 'Enter a building name and location (up to 150 characters each).');
            }
        } elseif (!$propertyId || !$propertyModel->find((int) $propertyId)) {
            return redirect()->back()->withInput()
                ->with('error', 'Select an existing building or choose Add new building.');
        }

        $unitNumber = trim(
            (string) $this->request->getPost('unit_number')
        );

        if ($unitNumber === '') {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Apartment number is required.');
        }

        $status = (string) $this->request->getPost('status');

        if (!in_array($status, [
            'active',
            'inactive',
            'maintenance'
        ], true)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Invalid apartment status.');
        }

        // Validate numeric fields before saving.
        $bedrooms = $this->request->getPost('bedrooms');
        $bathrooms = $this->request->getPost('bathrooms');
        $nightlyRate = $this->request->getPost('nightly_rate');
        $floorNumber = $this->request->getPost('floor_number');

        if (
            filter_var($bedrooms, FILTER_VALIDATE_INT) === false ||
            (int) $bedrooms < 0 ||
            filter_var($bathrooms, FILTER_VALIDATE_INT) === false ||
            (int) $bathrooms < 0 ||
            !is_numeric($nightlyRate) ||
            (float) $nightlyRate < 0 ||
            !is_finite((float) $nightlyRate)
        ) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Please enter valid apartment numbers and pricing.');
        }

        if (
            $floorNumber !== null &&
            $floorNumber !== '' &&
            filter_var($floorNumber, FILTER_VALIDATE_INT) === false
        ) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Please enter a valid floor number.');
        }

        $data = [
            'property_id' => $propertyId,
            'unit_number' => $unitNumber,
            'bedrooms' => (int) $bedrooms,
            'bathrooms' => (int) $bathrooms,
            'floor_number' => $floorNumber === '' || $floorNumber === null
                ? null
                : (int) $floorNumber,
            'nightly_rate' => (float) $nightlyRate,
            'owner_name' => trim(
                (string) $this->request->getPost('owner_name')
            ),
            'owner_phone' => trim(
                (string) $this->request->getPost('owner_phone')
            ),
            'status' => $status,
            'notes' => trim(
                (string) $this->request->getPost('notes')
            ),
        ];

        // Create a new building and apartment in the same transaction.
        // An unsuccessful apartment save must not leave an unused building behind.
        $db = db_connect();
        $db->transBegin();
        try {
            if ($creatingBuilding) {
                // Avoid silently creating duplicate building records.
                $existingBuildings = $propertyModel->select('id, name')->findAll();
                foreach ($existingBuildings as $building) {
                    if (mb_strtolower(trim($building['name'])) === mb_strtolower($buildingName)) {
                        throw new \RuntimeException('A building with this name already exists. Select it from the list instead.');
                    }
                }
                $newBuildingId = $propertyModel->insert([
                    'name' => $buildingName,
                    'location' => $buildingLocation,
                    'status' => 'active',
                ], true);
                if (!$newBuildingId) {
                    throw new \RuntimeException(implode(' ', $propertyModel->errors()) ?: 'Could not create the building.');
                }
                $propertyId = (int) $newBuildingId;
            }

            // Validate again after resolving the building.
            $duplicateQuery = $unitModel->where('property_id', (int) $propertyId)
                ->where('unit_number', $unitNumber);
            if ($id !== null) {
                $duplicateQuery->where('id !=', $id);
            }
            if ($duplicateQuery->first()) {
                throw new \RuntimeException('This apartment number already exists in the selected building.');
            }

            $data['property_id'] = (int) $propertyId;
            $saved = $id === null
                ? $unitModel->insert($data)
                : $unitModel->update($id, $data);
            if (!$saved || $db->transStatus() === false) {
                throw new \RuntimeException(implode(' ', $unitModel->errors()) ?: 'Unable to save apartment.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Apartment save failed: {error}', ['error' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('units'))
            ->with(
                'success',
                $id === null
                    ? 'Apartment added successfully.'
                    : 'Apartment updated successfully.'
            );
    }
}
