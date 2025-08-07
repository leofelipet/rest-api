<?php

namespace Webkul\RestApi\Http\Controllers\V1\Contact\Persons;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\RestApi\Http\Controllers\V1\Controller;
use Webkul\RestApi\Http\Request\MassDestroyRequest;
use Webkul\RestApi\Http\Resources\V1\Contact\PersonResource;
use Webkul\User\Repositories\UserRepository;

class PersonController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected PersonRepository $personRepository,
        protected UserRepository $userRepository,
    ) {
        $this->addEntityTypeInRequest('persons');
    }

    /**
     * Display a listing of the persons.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $persons = $this->allResources($this->personRepository);

        return PersonResource::collection($persons);
    }

    /**
     * Show resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(int $id)
    {
        $resource = $this->personRepository->find($id);

        return new PersonResource($resource);
    }

    /**
     * Search person results.
     */
    public function search(): JsonResource
    {
        $query = Person::query();
        
        // Get search parameters
        $searchParams = request()->get('search', []);
        
        if (is_string($searchParams)) {
            $searchParams = [$searchParams];
        }
        
        foreach ($searchParams as $param) {
            if (strpos($param, ':') !== false) {
                [$field, $value] = explode(':', $param, 2);
                
                if ($field === 'emails.value') {
                    $query->whereJsonContains('emails', ['value' => $value]);
                } elseif ($field === 'email') {
                    $query->whereJsonContains('emails', ['value' => $value]);
                } else {
                    // Only allow valid columns to be searched directly
                    $allowedColumns = ['name', 'job_title'];
                    if (in_array($field, $allowedColumns)) {
                        $query->where($field, 'like', "%{$value}%");
                    }
                }
            } else {
                $query->where(function ($q) use ($param) {
                    $q->where('name', 'like', "%{$param}%")
                      ->orWhereJsonContains('emails', ['value' => $param]);
                });
            }
        }
        
        // Apply user permissions
        if ($userIds = $this->getAuthorizedUserIds()) {
            $query->whereIn('user_id', $userIds);
        }
        
        $perPage = request()->get('per_page', 15);
        $persons = $query->with(['organization', 'attribute_values'])->paginate($perPage);
        
        return PersonResource::collection($persons);
    }

    /**
     * Create the person.
     *
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        $this->validate(request(), [
            'name' => 'required',
            'emails' => 'required|array',
            'emails.*.value' => 'required|email',
            'emails.*.label' => 'required',
            'contact_numbers' => 'required|array',
            'contact_numbers.*.value' => 'required',
            'contact_numbers.*.label' => 'required',
        ]);

        Event::dispatch('contacts.person.create.before');

        $person = $this->personRepository->create($this->sanitizeRequestedPersonData());

        Event::dispatch('contacts.person.create.after', $person);

        return new JsonResponse([
            'data'    => new PersonResource($person),
            'message' => trans('rest-api::app.contacts.persons.create-success'),
        ]);
    }

    /**
     * Update the person.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        $this->validate(request(), [
            'name' => 'required',
            'emails' => 'required|array',
            'emails.*.value' => 'required|email',
            'emails.*.label' => 'required',
            'contact_numbers' => 'required|array',
            'contact_numbers.*.value' => 'required',
            'contact_numbers.*.label' => 'required',
        ]);

        Event::dispatch('contacts.person.update.before', $id);

        $person = $this->personRepository->update($this->sanitizeRequestedPersonData(), $id);

        Event::dispatch('contacts.person.update.after', $person);

        return new JsonResponse([
            'data'    => new PersonResource($person),
            'message' => trans('rest-api::app.contacts.persons.update-success'),
        ]);
    }

    /**
     * Remove the person.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            Event::dispatch('contacts.person.delete.before', $id);

            $this->personRepository->delete($id);

            Event::dispatch('contacts.person.delete.after', $id);

            return new JsonResponse([
                'message' => trans('rest-api::app.response.delete-success'),
            ]);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'message' => trans('rest-api::app.contacts.persons.delete-success'),
            ], 500);
        }
    }

    /**
     * Mass delete the persons.
     *
     * @return \Illuminate\Http\Response
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest)
    {
        $personIds = $massDestroyRequest->input('indices', []);

        foreach ($personIds as $personId) {
            $person = $this->personRepository->find($personId);

            if (! $person) {
                continue;
            }

            Event::dispatch('contact.person.delete.before', $personId);

            $person->delete($personId);

            Event::dispatch('contact.person.delete.after', $personId);
        }

        return new JsonResponse([
            'message' => trans('rest-api::app.contacts.persons.delete-success'),
        ]);
    }

    /**
     * Sanitize requested person data and return the clean array.
     */
    private function sanitizeRequestedPersonData(): array
    {
        $data = request()->all();

        // Ensure 'contact_numbers' exists and is an array before processing
        if (! isset($data['contact_numbers'])) {
            $data['contact_numbers'] = [];
        }

        // Existing contact_numbers processing...
        if (isset($data['contact_numbers'])) {
            $data['contact_numbers'] = collect($data['contact_numbers'])
                ->filter(function ($contactNumber) {
                    return ! is_null($contactNumber['value'] ?? null);
                })
                ->values()
                ->toArray();
        }

        return $data;
    }

    /**
     * Get the authorized user ids.
     */
    private function getAuthorizedUserIds(): ?array
    {
        $user = auth()->user();

        if ($user->view_permission == 'global') {
            return null;
        }

        if ($user->view_permission == 'group') {
            return $this->userRepository->getCurrentUserGroupsUserIds();
        } else {
            return [$user->id];
        }
    }
}
