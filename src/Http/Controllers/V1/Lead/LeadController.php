<?php

namespace Webkul\RestApi\Http\Controllers\V1\Lead;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\Http\Requests\LeadForm;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\DataGrid\Enums\DateRangeOptionEnum;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Lead\Repositories\ProductRepository;
use Webkul\Lead\Repositories\SourceRepository;
use Webkul\Lead\Repositories\StageRepository;
use Webkul\Lead\Repositories\TypeRepository;
use Webkul\RestApi\Http\Controllers\V1\Controller;
use Webkul\Lead\Models\Lead;
use Webkul\RestApi\Http\Request\MassDestroyRequest;
use Webkul\RestApi\Http\Request\MassUpdateRequest;
use Webkul\RestApi\Http\Resources\V1\Lead\LeadResource;
use Webkul\RestApi\Http\Resources\V1\Setting\StageResource;
use Webkul\Tag\Repositories\TagRepository;
use Webkul\User\Repositories\UserRepository;

class LeadController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected LeadRepository $leadRepository,
        protected PipelineRepository $pipelineRepository,
        protected StageRepository $stageRepository,
        protected AttributeRepository $attributeRepository,
        protected UserRepository $userRepository,
        protected ProductRepository $productRepository,
        protected SourceRepository $sourceRepository,
        protected TypeRepository $typeRepository,
    ) {
        $this->addEntityTypeInRequest('leads');
    }

    /**
     * Returns a listing of the leads.
     */
    public function index(): JsonResource
    {
        $leads = $this->allResources($this->leadRepository);

        return LeadResource::collection($leads);
    }

    /**
     * Show resource.
     */
    public function show(int $id): JsonResource
    {
        $resource = $this->leadRepository->findOrFail($id);

        return new LeadResource($resource);
    }

    /**
     * Store a newly created lead in storage.
     */
    public function store(LeadForm $request): JsonResource
    {
        Event::dispatch('lead.create.before');

        $data = $request->all();

        $data['status'] = 1;

        if (isset($data['lead_pipeline_stage_id']) && $data['lead_pipeline_stage_id']) {
            $stage = $this->stageRepository->findOrFail($data['lead_pipeline_stage_id']);

            $data['lead_pipeline_id'] = $stage->lead_pipeline_id;
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();

            $stage = $pipeline->stages()->first();

            $data['lead_pipeline_id'] = $pipeline->id;

            $data['lead_pipeline_stage_id'] = $stage->id;
        }

        if (in_array($stage->code, ['won', 'lost'])) {
            $data['closed_at'] = Carbon::now();
        }

        $lead = $this->leadRepository->create($data);

        Event::dispatch('lead.create.after', $lead);

        return new JsonResource([
            'data'    => new LeadResource($lead),
            'message' => trans('rest-api::app.leads.create-success'),
        ]);
    }

    /**
     * Update the lead in storage.
     */
    public function update(LeadForm $request, int $id): JsonResource
    {
        Event::dispatch('lead.update.before', $id);

        $data = $request->all();

        if (isset($data['lead_pipeline_stage_id']) && $data['lead_pipeline_stage_id']) {
            $stage = $this->stageRepository->findOrFail($data['lead_pipeline_stage_id']);

            $data['lead_pipeline_id'] = $stage->lead_pipeline_id;
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();

            $stage = $pipeline->stages()->first();

            $data['lead_pipeline_id'] = $pipeline->id;

            $data['lead_pipeline_stage_id'] = $stage->id;
        }

        $lead = $this->leadRepository->update($data, $id);

        Event::dispatch('lead.update.after', $lead);

        return new JsonResource([
            'data'    => new LeadResource($lead),
            'message' => trans('rest-api::app.leads.updated-success'),
        ]);
    }

    /**
     * Update the lead attributes.
     */
    public function updateAttributes(int $id): JsonResource
    {
        $data = request()->all();

        $attributes = $this->attributeRepository->findWhere([
            'entity_type' => 'leads',
            ['code', 'NOTIN', ['title', 'description']],
        ]);

        Event::dispatch('lead.update.before', $id);

        $lead = $this->leadRepository->update($data, $id, $attributes);

        Event::dispatch('lead.update.after', $lead);

        return new JsonResource([
            'data'    => new LeadResource($lead),
            'message' => trans('rest-api::app.leads.updated-success'),
        ]);
    }

    /**
     * Update the lead stage.
     */
    public function updateStage(int $id): JsonResource
    {
        $this->validate(request(), [
            'lead_pipeline_stage_id' => 'required',
        ]);

        $lead = $this->leadRepository->findOrFail($id);

        $stage = $lead->pipeline->stages()
            ->where('id', request()->input('lead_pipeline_stage_id'))
            ->firstOrFail();

        Event::dispatch('lead.update.before', $id);

        $lead = $this->leadRepository->update(
            [
                'entity_type'            => 'leads',
                'lead_pipeline_stage_id' => $stage->id,
            ],
            $id,
            ['lead_pipeline_stage_id']
        );

        Event::dispatch('lead.update.after', $lead);

        return new JsonResource([
            'data'    => new LeadResource($lead),
            'message' => trans('rest-api::app.leads.updated-success'),
        ]);
    }

    /**
     * Search person results.
     */
    public function search(): AnonymousResourceCollection
    {
        $query = Lead::query();
        
        // Get search parameters
        $searchParams = request()->get('search', []);
        
        if (is_string($searchParams)) {
            $searchParams = [$searchParams];
        }
        
        foreach ($searchParams as $param) {
            if (strpos($param, ':') !== false) {
                [$field, $value] = explode(':', $param, 2);
                
                switch ($field) {
                    case 'person.emails.value':
                        $query->whereHas('person', function ($q) use ($value) {
                            $q->whereJsonContains('emails', ['value' => $value]);
                        });
                        break;
                        
                    case 'stage.code':
                        $query->whereHas('stage', function ($q) use ($value) {
                            $q->where('code', $value);
                        });
                        break;
                        
                    case 'status':
                        if ($value === 'open') {
                            $query->whereHas('stage', function ($q) {
                                $q->whereNotIn('code', ['won', 'lost']);
                            });
                        }
                        break;
                        
                    default:
                        $query->where($field, 'like', "%{$value}%");
                        break;
                }
            } else {
                $query->where(function ($q) use ($param) {
                    $q->where('title', 'like', "%{$param}%")
                      ->orWhere('description', 'like', "%{$param}%")
                      ->orWhereHas('person', function ($personQuery) use ($param) {
                          $personQuery->where('name', 'like', "%{$param}%");
                      });
                });
            }
        }
        
        // Apply user permissions
        if ($userIds = $this->getAuthorizedUserIds()) {
            $query->whereIn('user_id', $userIds);
        }
        
        $perPage = request()->get('per_page', 15);
        $leads = $query->with([
            'person', 
            'person.organization', 
            'person.attribute_values',
            'stage', 
            'source', 
            'type'
        ])->paginate($perPage);
        
        return LeadResource::collection($leads);
    }

    /**
     * Returns a listing of the resource.
     */
    public function get(): JsonResource
    {
        if (request()->query('pipeline_id')) {
            $pipeline = $this->pipelineRepository->find(request()->query('pipeline_id'));
        } else {
            $pipeline = $this->pipelineRepository->getDefaultPipeline();
        }

        if (request()->query('pipeline_stage_id')) {
            $stages = $pipeline->stages->where('id', request()->query('pipeline_stage_id'));
        } else {
            $stages = $pipeline->stages;
        }

        foreach ($stages as $stage) {
            /**
             * We have to create a new instance of the lead repository every time, which is
             * why we're not using the injected one.
             */
            $query = app(LeadRepository::class)
                ->pushCriteria(app(RequestCriteria::class))
                ->where([
                    'lead_pipeline_id'       => $pipeline->id,
                    'lead_pipeline_stage_id' => $stage->id,
                ]);

            if ($userIds = $this->getAuthorizedUserIds()) {
                $query->whereIn('leads.user_id', $userIds);
            }

            $stage->lead_value = (clone $query)->sum('lead_value');

            $data[$stage->id] = (new StageResource($stage))->jsonSerialize();

            $data[$stage->id]['leads'] = [
                'data' => LeadResource::collection($paginator = $query->with([
                    'tags',
                    'type',
                    'source',
                    'user',
                    'person',
                    'person.organization',
                    'person.attribute_values',
                    'pipeline',
                    'pipeline.stages',
                    'stage',
                    'attribute_values',
                ])->paginate(10)),

                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'from'         => $paginator->firstItem(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'to'           => $paginator->lastItem(),
                    'total'        => $paginator->total(),
                ],
            ];
        }

        return new JsonResource($data);
    }

    /**
     * Attach product to lead.
     */
    public function addProduct(int $leadId): JsonResource
    {
        $product = $this->productRepository->updateOrCreate(
            [
                'lead_id'    => $leadId,
                'product_id' => request()->input('product_id'),
            ],
            array_merge(
                request()->all(),
                [
                    'lead_id' => $leadId,
                    'amount'  => request()->input('price') * request()->input('quantity'),
                ],
            )
        );

        return new JsonResource([
            'data'    => $product,
            'message' => trans('rest-api::app.leads.updated-success'),
        ]);
    }

    /**
     * Kanban lookup.
     */
    public function kanbanLookup()
    {
        $params = $this->validate(request(), [
            'column' => ['required'],
            'search' => ['required', 'min:2'],
        ]);

        /**
         * Finding the first column from the collection.
         */
        $column = collect($this->getKanbanColumns())->where('index', $params['column'])->firstOrFail();

        /**
         * Fetching on the basis of column options.
         */
        return app($column['filterable_options']['repository'])
            ->select([$column['filterable_options']['column']['label'].' as label', $column['filterable_options']['column']['value'].' as value'])
            ->where($column['filterable_options']['column']['label'], 'LIKE', '%'.$params['search'].'%')
            ->get()
            ->map
            ->only('label', 'value');
    }

    /**
     * Remove product attached to lead.
     */
    public function removeProduct(int $id): JsonResource
    {
        try {
            Event::dispatch('lead.product.delete.before', $id);

            $this->productRepository->deleteWhere([
                'lead_id'    => $id,
                'product_id' => request()->input('product_id'),
            ]);

            Event::dispatch('lead.product.delete.after', $id);

            return new JsonResource([
                'message' => trans('rest-api::app.leads.delete-success'),
            ]);
        } catch (\Exception $exception) {
            return new JsonResource([
                'message' => trans('rest-api::app.leads.delete-failed'),
            ]);
        }
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

    /**
     * Remove the lead from storage.
     */
    public function destroy(int $id): JsonResource
    {
        $lead = $this->leadRepository->findOrFail($id);

        try {
            Event::dispatch('lead.delete.before', $id);

            $lead->delete();

            Event::dispatch('lead.delete.after', $id);

            return new JsonResource([
                'message' => trans('rest-api::app.leads.delete-success'),
            ]);
        } catch (\Exception $exception) {
            return new JsonResource([
                'message' => trans('rest-api::app.leads.delete-failed'),
            ], 500);
        }
    }

    /**
     * Mass update the leads.
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResource
    {
        $leadIds = $massUpdateRequest->input('indices', []);

        foreach ($leadIds as $leadId) {
            $lead = $this->leadRepository->find($leadId);

            if (! $lead) {
                continue;
            }

            Event::dispatch('lead.update.before', $leadId);

            $lead->update(['lead_pipeline_stage_id' => $massUpdateRequest->input('value')]);

            Event::dispatch('lead.update.before', $leadId);
        }

        return new JsonResource([
            'message' => trans('rest-api::app.leads.updated-success'),
        ]);
    }

    /**
     * Mass delete the leads.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResource
    {
        $leadIds = $massDestroyRequest->input('indices', []);

        foreach ($leadIds as $leadId) {
            $lead = $this->leadRepository->find($leadId);

            if (! $lead) {
                continue;
            }

            Event::dispatch('lead.delete.before', $leadId);

            $lead->delete();

            Event::dispatch('lead.delete.after', $leadId);
        }

        return new JsonResource([
            'message' => trans('rest-api::app.leads.delete-success'),
        ]);
    }

    /**
     * Get columns for the kanban view.
     */
    private function getKanbanColumns(): array
    {
        return [
            [
                'index'                 => 'id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.id'),
                'type'                  => 'integer',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => null,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'lead_value',
                'label'                 => trans('admin::app.leads.index.kanban.columns.lead-value'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => null,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'user_id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.sales-person'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'searchable_dropdown',
                'filterable_options'    => [
                    'repository' => UserRepository::class,
                    'column'     => [
                        'label' => 'name',
                        'value' => 'id',
                    ],
                ],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'person.id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.contact-person'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
                'filterable_type'       => 'searchable_dropdown',
                'filterable_options'    => [
                    'repository' => PersonRepository::class,
                    'column'     => [
                        'label' => 'name',
                        'value' => 'id',
                    ],
                ],
            ],
            [
                'index'                 => 'lead_type_id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.lead-type'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'dropdown',
                'filterable_options'    => $this->typeRepository->all(['name as label', 'id as value'])->toArray(),
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],
            [
                'index'                 => 'lead_source_id',
                'label'                 => trans('admin::app.leads.index.kanban.columns.source'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_type'       => 'dropdown',
                'filterable_options'    => $this->sourceRepository->all(['name as label', 'id as value'])->toArray(),
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
            ],

            [
                'index'                 => 'tags.name',
                'label'                 => trans('admin::app.leads.index.kanban.columns.tags'),
                'type'                  => 'string',
                'searchable'            => false,
                'search_field'          => 'in',
                'filterable'            => true,
                'filterable_options'    => [],
                'allow_multiple_values' => true,
                'sortable'              => true,
                'visibility'            => true,
                'filterable_type'       => 'searchable_dropdown',
                'filterable_options'    => [
                    'repository' => TagRepository::class,
                    'column'     => [
                        'label' => 'name',
                        'value' => 'name',
                    ],
                ],
            ],

            [
                'index'              => 'expected_close_date',
                'label'              => trans('admin::app.leads.index.kanban.columns.expected-close-date'),
                'type'               => 'date',
                'searchable'         => false,
                'searchable'         => false,
                'sortable'           => true,
                'filterable'         => true,
                'filterable_type'    => 'date_range',
                'filterable_options' => DateRangeOptionEnum::options(),
            ],

            [
                'index'              => 'created_at',
                'label'              => trans('admin::app.leads.index.kanban.columns.created-at'),
                'type'               => 'date',
                'searchable'         => false,
                'searchable'         => false,
                'sortable'           => true,
                'filterable'         => true,
                'filterable_type'    => 'date_range',
                'filterable_options' => DateRangeOptionEnum::options(),
            ],
        ];
    }
}
