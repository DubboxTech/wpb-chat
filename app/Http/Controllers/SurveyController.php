<?php

namespace App\Http\Controllers;

use App\Models\RestaurantSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SurveyController extends Controller
{
    /**
     * Exibe a página de análise de pesquisas.
     */
    public function index(Request $request)
    {
        $surveysQuery = RestaurantSurvey::with('contact:id,name');

        // Filtro por nome do restaurante
        if ($request->filled('restaurant')) {
            $surveysQuery->where('restaurant_name', $request->restaurant);
        }

        // Filtro por nome do usuário (contato)
        if ($request->filled('search')) {
            $search = $request->search;
            $surveysQuery->whereHas('contact', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            });
        }

        // Adiciona dados demográficos mockados a cada pesquisa para a tabela
        $surveys = $surveysQuery->latest()->paginate(15)->withQueryString()->through(function ($survey) {
            $survey->demographics = $this->getMockDemographicsForUser();
            return $survey;
        });

        $stats = $this->getSurveyStats($request);
        $allRestaurants = RestaurantSurvey::distinct()->pluck('restaurant_name');
        $heatmapData = $this->getHeatmapData($request);
        
        // Adiciona os dados demográficos para os gráficos
        $demographicStats = $this->getDemographicStats();

        return Inertia::render('Survey/Index', [
            'surveys' => $surveys,
            'stats' => $stats,
            'filters' => $request->only(['restaurant', 'search']),
            'allRestaurants' => $allRestaurants,
            'heatmapData' => $heatmapData,
            'demographicStats' => $demographicStats,
        ]);
    }

    /**
     * Calcula as estatísticas agregadas para os cards e gráficos.
     */
    private function getSurveyStats(Request $request): array
    {
        $query = RestaurantSurvey::query();
        if ($request->filled('restaurant')) {
            $query->where('restaurant_name', $request->restaurant);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('contact', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $totalSurveys = $query->count();
        $averageRating = $query->avg('rating');

        $ratingsDistribution = $query->select('rating', DB::raw('count(*) as count'))
            ->whereNotNull('rating')
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->pluck('count', 'rating');

        $surveysByDay = $query->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->limit(30)
            ->get()
            ->pluck('count', 'date');

        return [
            'totalSurveys' => $totalSurveys,
            'averageRating' => number_format($averageRating, 2),
            'ratingsDistribution' => [
                'labels' => $ratingsDistribution->keys()->map(fn($r) => "$r Estrelas"),
                'data' => $ratingsDistribution->values(),
            ],
            'surveysByDay' => [
                'labels' => $surveysByDay->keys(),
                'data' => $surveysByDay->values(),
            ]
        ];
    }
    
    /**
     * Gera estatísticas demográficas simuladas com base no PDF.
     */
    private function getDemographicStats(): array
    {
        // Dados inspirados na Figura 1 (pág. 16)
        $ageDistribution = [
            'labels' => ['18 a 29', '30 a 39', '40 a 49', '50 a 59', 'Acima de 60'],
            'data' => [21.9, 22.0, 21.0, 17.2, 17.8],
        ];

        // Dados inspirados na Tabela 3 (pág. 16)
        $genderDistribution = [
            'labels' => ['Masculino', 'Feminino'],
            'data' => [67.1, 32.9],
        ];

        // Dados inspirados na Tabela 10 (pág. 26)
        $benefitsByRestaurant = [
            'labels' => ['Gama', 'Ceilândia', 'Planaltina', 'Samambaia', 'SCIA/Estrutural'],
            'datasets' => [
                ['label' => 'Bolsa Família', 'data' => [46.4, 31.8, 24.8, 11.0, 9.0], 'backgroundColor' => '#3b82f6'],
                ['label' => 'Bolsa Escola-DF', 'data' => [28.0, 11.9, 1.2, 11.0, 1.9], 'backgroundColor' => '#16a34a'],
                ['label' => 'BPC', 'data' => [5.5, 5.1, 6.3, 1.4, 1.9], 'backgroundColor' => '#f97316'],
            ],
        ];
        
        // Dados para o gráfico de beneficiários do CadÚnico
        $cadunicoDistribution = [
            'labels' => ['Sim', 'Não'],
            'data' => [78.3, 21.7], // Dado inspirado na Tabela 9 (pág. 25) do PDF
        ];

        return [
            'ageDistribution' => $ageDistribution,
            'genderDistribution' => $genderDistribution,
            'benefitsByRestaurant' => $benefitsByRestaurant,
            'cadunicoDistribution' => $cadunicoDistribution,
        ];
    }
    
    /**
     * Simula dados demográficos para um usuário individual na tabela.
     */
    private function getMockDemographicsForUser(): array
    {
        $genders = ['Masculino', 'Feminino'];
        $schooling = ['Fundamental Incompleto', 'Médio Completo', 'Superior Incompleto', 'Superior Completo'];
        
        return [
            'gender' => $genders[array_rand($genders)],
            'age' => rand(18, 75),
            'schooling' => $schooling[array_rand($schooling)],
            'income' => 'R$ ' . number_format(rand(700, 3500) / 10 * 10, 2, ',', '.'),
            'is_cadunico_beneficiary' => (bool)rand(0, 1),
        ];
    }

    /**
     * Prepara os dados de geolocalização para o mapa de calor.
     */
    private function getHeatmapData(Request $request): array
    {
        $query = RestaurantSurvey::query()->whereNotNull('cep');

        if ($request->filled('restaurant')) {
            $query->where('restaurant_name', $request->restaurant);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('contact', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->get(['cep', 'rating'])->map(function ($survey) {
            $cep = preg_replace('/\D/', '', $survey->cep);
            if (strlen($cep) !== 8) return null;

            $coords = $this->getCoordsForCep($cep);
            if(!$coords) return null;

            // Retorna latitude, longitude e intensidade (avaliação)
            return [$coords['lat'], $coords['lng'], (float) $survey->rating];
        })->filter()->values()->all();
    }

    /**
     * Simula a busca de coordenadas para um CEP (substituir por API real).
     */
    private function getCoordsForCep(string $cep): ?array
    {
        // Base de coordenadas de Brasília
        $baseLat = -15.7942;
        $baseLng = -47.8825;

        // Gera um desvio aleatório para simular diferentes localizações
        $lat = $baseLat + (rand(-500, 500) / 10000);
        $lng = $baseLng + (rand(-500, 500) / 10000);

        return ['lat' => $lat, 'lng' => $lng];
    }
}