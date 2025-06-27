<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppContact;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = WhatsAppContact::query();

        // Filtro por pesquisa (nome ou telefone)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('phone_number', 'like', '%' . $search . '%');
            });
        }

        // Filtro por tag (segmento)
        if ($request->filled('tag')) {
            $query->whereJsonContains('tags', $request->tag);
        }

        $contacts = $query->orderBy('name', 'asc')->paginate(15)->withQueryString();

        // Obter todas as tags únicas para o filtro de segmentos
        $allTags = WhatsAppContact::whereNotNull('tags')->select('tags')->get()->pluck('tags')->flatten()->unique()->values()->all();

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'segments' => $allTags,
            'filters' => $request->only(['search', 'tag']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->validateContact($request)->validate();

        $data = $request->all();
        $data['custom_fields'] = $this->formatCustomFieldsForDatabase($request->custom_fields);

        WhatsAppContact::create($data);

        return redirect()->route('contacts.index')->with('success', 'Contato criado com sucesso.');
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WhatsAppContact $contact)
    {
        $this->validateContact($request, $contact->id)->validate();

        $data = $request->all();
        $data['custom_fields'] = $this->formatCustomFieldsForDatabase($request->custom_fields);
        
        $contact->update($data);

        return redirect()->route('contacts.index')->with('success', 'Contato atualizado com sucesso.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WhatsAppContact $contact)
    {
        $contact->delete();
        return redirect()->route('contacts.index')->with('success', 'Contato excluído com sucesso.');
    }

    /**
     * Valida os dados do contato.
     */
    private function validateContact(Request $request, $contactId = null)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20|unique:whatsapp_contacts,phone_number,' . $contactId,
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'custom_fields' => 'nullable|array',
            'custom_fields.*.key' => 'required_with:custom_fields.*.value|string|max:50',
            'custom_fields.*.value' => 'required_with:custom_fields.*.key|string|max:255',
        ]);
    }

    /**
     * Formata os campos personalizados de um array de objetos para um objeto JSON.
     */
    private function formatCustomFieldsForDatabase(?array $customFields): ?array
    {
        if (empty($customFields)) {
            return null;
        }

        return collect($customFields)->reduce(function ($carry, $item) {
            if (!empty($item['key'])) {
                $carry[$item['key']] = $item['value'];
            }
            return $carry;
        }, []);
    }
}