<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * Handle a file upload, store it in the cloud, and return its public URL.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,gif,mp4,3gp,ogg,amr,pdf', // Mime types permitidos
                'max:16384', // Limite de 16MB (WhatsApp para vídeos)
            ],
            'conversation_id' => 'required|exists:whatsapp_conversations,id',
        ]);

        try {
            $file = $request->file('file');
            $conversationId = $request->input('conversation_id');
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $extension = $file->getClientOriginalExtension();
            
            // Cria um nome de arquivo seguro e único
            $fileName = Str::slug($originalName) . '-' . Str::random(8) . '.' . $extension;
            
            // Define o caminho no storage
            $filePath = "media/{$conversationId}/{$fileName}";

            // Salva o arquivo no disco 's3' (seu DigitalOcean Spaces) com visibilidade pública
            Storage::disk('s3')->put($filePath, $file->get(), 'public');

            // Obtém a URL pública do arquivo salvo
            $publicUrl = Storage::disk('s3')->url($filePath);

            return response()->json([
                'success' => true,
                'url' => $publicUrl,
                'filename' => $file->getClientOriginalName()
            ]);

        } catch (\Exception $e) {
            \Log::error('Media upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Falha no upload do arquivo.'
            ], 500);
        }
    }
}