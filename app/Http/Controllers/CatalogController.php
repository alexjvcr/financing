<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pantallas compartidas de búsqueda, listado, creación, edición y eliminación para las tablas de configuración.
 */
abstract class CatalogController extends Controller
{
    protected const PER_PAGE = 15;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    abstract protected function title(): string;

    abstract protected function singular(): string;

    /**
     * Prefijo del nombre de ruta, por ejemplo "empresas".
     */
    abstract protected function routeBase(): string;

    /**
     * Etiqueta de columna => callback que renderiza la celda de un registro.
     *
     * @return array<string, callable(Model): mixed>
     */
    abstract protected function columns(): array;

    /**
     * Nombre del campo => definición (etiqueta, tipo, opciones, paso, ayuda, obligatorio).
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function fields(): array;

    /**
     * @return array<string, array<int, mixed>>
     */
    abstract protected function rules(?Model $record): array;

    /**
     * Título de sección => ícono y descripción, para formularios que agrupan sus campos.
     *
     * @return array<string, array{icon: string, description: string}>
     */
    protected function groups(): array
    {
        return [];
    }

    /**
     * Columnas en las que busca el cuadro de búsqueda.
     *
     * @return array<int, string>
     */
    protected function searchColumns(): array
    {
        return [];
    }

    /**
     * Relaciones que necesita el listado, para evitar una consulta por fila.
     *
     * @return array<int, string>
     */
    protected function eagerLoads(): array
    {
        return [];
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $query) use ($term): void {
            foreach ($this->searchColumns() as $column) {
                $query->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    /**
     * Motivos por los que un registro no se puede eliminar porque otros registros lo referencian.
     *
     * @return array<int, string>
     */
    protected function deletionBlockers(Model $record): array
    {
        return [];
    }

    /**
     * Elimina el registro y todo lo que solo existe para describirlo.
     */
    protected function deleteRecord(Model $record): void
    {
        $record->delete();
    }

    /**
     * Permite que una pantalla cambie sus campos para el registro que se edita, por ejemplo para bloquear algunos.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    protected function adjustFields(array $fields, ?Model $record): array
    {
        return $fields;
    }

    /**
     * Valores de atributos derivados de los datos validados.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepare(array $data, ?Model $record): array
    {
        return $data;
    }

    protected function afterSave(Model $record, ?array $original): void {}

    /**
     * @return array<string, mixed>
     */
    protected function extraFormData(?Model $record): array
    {
        return [];
    }

    /**
     * Texto usado para nombrar un registro en mensajes y cuadros de confirmación.
     */
    protected function recordLabel(Model $record): string
    {
        $firstColumn = array_values($this->columns())[0];

        return (string) $firstColumn($record);
    }

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        $query = $this->model()::query()->with($this->eagerLoads())->orderBy('id');
        if ($term !== '') {
            $this->applySearch($query, $term);
        }

        $records = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('catalog.index', [
            'title' => $this->title(),
            'singular' => $this->singular(),
            'routeBase' => $this->routeBase(),
            'columns' => $this->columns(),
            'records' => $records,
            'term' => $term,
            'blockers' => $records->getCollection()->mapWithKeys(fn (Model $record): array => [$record->getKey() => $this->deletionBlockers($record)])->all(),
            'labels' => $records->getCollection()->mapWithKeys(fn (Model $record): array => [$record->getKey() => $this->recordLabel($record)])->all(),
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->prepare($request->validate($this->rules(null)), null);

        $record = $this->model()::create($data);
        $this->afterSave($record, null);

        return redirect()->route($this->routeBase().'.index')->with('status', $this->singular().' creado correctamente.');
    }

    public function edit(string $record): View
    {
        return $this->form($this->model()::findOrFail($record));
    }

    public function update(Request $request, string $record): RedirectResponse
    {
        $model = $this->model()::findOrFail($record);
        $original = $model->getOriginal();
        $data = $this->prepare($request->validate($this->rules($model)), $model);

        $model->update($data);
        $this->afterSave($model, $original);

        return redirect()->route($this->routeBase().'.index')->with('status', $this->singular().' actualizado correctamente.');
    }

    public function destroy(string $record): RedirectResponse
    {
        $model = $this->model()::findOrFail($record);
        $label = $this->recordLabel($model);
        $blockers = $this->deletionBlockers($model);

        if ($blockers !== []) {
            return back()->withErrors(['delete' => "No se puede eliminar «{$label}»: ".implode(' ', $blockers)]);
        }

        DB::transaction(fn () => $this->deleteRecord($model));

        return redirect()->route($this->routeBase().'.index')->with('status', "{$this->singular()} «{$label}» eliminado correctamente.");
    }

    protected function form(?Model $record): View
    {
        return view('catalog.form', [
            'title' => $this->title(),
            'singular' => $this->singular(),
            'routeBase' => $this->routeBase(),
            'fields' => $this->adjustFields($this->fields(), $record),
            'groups' => $this->groups(),
            'record' => $record,
        ] + $this->extraFormData($record));
    }
}
