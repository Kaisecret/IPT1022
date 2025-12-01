
import { GoogleGenAI, Type } from "@google/genai";

// --- API Configuration ---
const ai = new GoogleGenAI({ apiKey: process.env.API_KEY });

// --- State Management & LocalStorage Helpers ---
const STORAGE_KEYS = {
    APP_STATE: 'physique_app_state', // 'landing' or 'dashboard'
    CURRENT_REPORT: 'physique_current_report',
    PLANS: 'physique_plans',
    HISTORY: 'physique_history',
    PREFERENCES: 'physique_preferences'
};

const state = {
    get: (key, defaultVal) => {
        const val = localStorage.getItem(key);
        return val ? JSON.parse(val) : defaultVal;
    },
    set: (key, val) => localStorage.setItem(key, JSON.stringify(val)),
    clear: () => localStorage.clear()
};

// Default Preferences
const defaultPrefs = {
    goal: 'muscle gain',
    experience: 'beginner',
    equipment: 'gym',
    time: '45-60 min',
    blurFace: true,
    theme: 'dark'
};

// --- Gemini Service Logic (Converted from TS) ---
const fileToGenerativePart = async (file) => {
    const base64EncodedDataPromise = new Promise((resolve) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve((reader.result).split(',')[1]);
        reader.readAsDataURL(file);
    });
    return {
        inlineData: { data: await base64EncodedDataPromise, mimeType: file.type },
    };
};

// Schemas (Identical to original)
const muscleDetailSchema = {
    type: Type.OBJECT,
    properties: {
        score: { type: Type.NUMBER, description: "Score from 1 to 10 for the muscle group." },
        strengths: { type: Type.STRING, description: "Observed strengths for this muscle group." },
        weaknesses: { type: Type.STRING, description: "Observed weaknesses or areas for improvement." },
        symmetryNotes: { type: Type.STRING, description: "Notes on muscular symmetry." },
    },
    required: ["score", "strengths", "weaknesses", "symmetryNotes"],
};

const physiqueAnalysisReportSchema = {
    type: Type.OBJECT,
    properties: {
        muscleAnalysis: {
            type: Type.OBJECT,
            properties: {
                chest: muscleDetailSchema,
                abs: muscleDetailSchema,
                arms: muscleDetailSchema,
                back: muscleDetailSchema,
                legs: muscleDetailSchema,
            },
            required: ["chest", "abs", "arms", "back", "legs"],
        },
        physiqueRating: {
            type: Type.OBJECT,
            properties: {
                overallScore: { type: Type.NUMBER, description: "Overall physique score from 1 to 10." },
                summary: { type: Type.STRING, description: "A concise summary of the overall physique." },
            },
            required: ["overallScore", "summary"],
        },
        postureNotes: { type: Type.STRING, description: "Observations and suggestions regarding posture." },
    },
    required: ["muscleAnalysis", "physiqueRating", "postureNotes"],
};

const exerciseSchema = {
    type: Type.OBJECT,
    properties: {
        name: { type: Type.STRING },
        sets: { type: Type.STRING },
        reps: { type: Type.STRING },
        rest: { type: Type.STRING },
    },
    required: ["name", "sets", "reps", "rest"],
};

const dailyWorkoutSchema = {
    type: Type.OBJECT,
    properties: {
        dayOfWeek: { type: Type.STRING },
        targetMuscle: { type: Type.STRING },
        warmup: { type: Type.STRING },
        exercises: {
            type: Type.ARRAY,
            items: exerciseSchema,
        },
        cooldown: { type: Type.STRING },
        progressiveOverloadTip: { type: Type.STRING },
    },
    required: ["dayOfWeek", "targetMuscle", "warmup", "exercises", "cooldown", "progressiveOverloadTip"],
};

const workoutPlanSchema = {
    type: Type.OBJECT,
    properties: {
        plan: {
            type: Type.ARRAY,
            items: dailyWorkoutSchema,
        },
    },
    required: ["plan"],
};

const mealSchema = {
    type: Type.OBJECT,
    properties: {
        name: { type: Type.STRING },
        ingredients: {
            type: Type.ARRAY,
            items: { type: Type.STRING },
        },
        notes: { type: Type.STRING },
    },
    required: ["name", "ingredients", "notes"],
};

const mealGuideSchema = {
    type: Type.OBJECT,
    properties: {
        dailyCalorieTarget: { type: Type.NUMBER },
        macros: {
            type: Type.OBJECT,
            properties: {
                protein: { type: Type.STRING },
                carbs: { type: Type.STRING },
                fats: { type: Type.STRING },
            },
            required: ["protein", "carbs", "fats"],
        },
        meals: {
            type: Type.ARRAY,
            items: mealSchema,
        },
        mealSwaps: {
            type: Type.ARRAY,
            items: { type: Type.STRING },
        },
    },
    required: ["dailyCalorieTarget", "macros", "meals", "mealSwaps"],
};

const plansSchema = {
    type: Type.OBJECT,
    properties: {
        workoutPlan: workoutPlanSchema,
        mealGuide: mealGuideSchema,
    },
    required: ["workoutPlan", "mealGuide"],
};

async function analyzePhysique(images, preferences) {
    const imageParts = await Promise.all(images.map(fileToGenerativePart));
    const prompt = `You are an expert fitness coach and physique analyst. Analyze the following images of a person's physique.
    
    User Personal Details:
    - Sex: ${preferences.sex}
    - Age: ${preferences.age}
    - Height: ${preferences.height} cm
    - Weight: ${preferences.weight} kg
    - Activity Level: ${preferences.activity}

    User Workout Preferences:
    - Goal: ${preferences.goal}
    - Experience Level: ${preferences.experience}
    - Available Equipment: ${preferences.equipment}
    - Time per workout: ${preferences.time}
    
    Instructions:
    ${preferences.enablePose ? "- Please pay special attention to posture and alignment as the user has requested pose analysis." : ""}
    
    Based on the images and their preferences, provide a detailed analysis. Return JSON only.`;

    const response = await ai.models.generateContent({
        model: 'gemini-2.5-flash',
        contents: { parts: [...imageParts, { text: prompt }] },
        config: {
            responseMimeType: 'application/json',
            responseSchema: physiqueAnalysisReportSchema,
        },
    });
    return JSON.parse(response.text);
}

async function generatePlans(analysis, preferences) {
    const prompt = `You are an expert fitness coach and nutritionist. Based on the following physique analysis and user preferences, create a personalized weekly workout plan and a daily meal guide.
    Physique Analysis: ${JSON.stringify(analysis, null, 2)}
    
    User Personal Details:
    - Sex: ${preferences.sex}
    - Age: ${preferences.age}
    - Height: ${preferences.height} cm
    - Weight: ${preferences.weight} kg
    - Activity Level: ${preferences.activity}
    - Goal: ${preferences.goal}
    
    User Workout Preferences:
    - Experience Level: ${preferences.experience}
    - Available Equipment: ${preferences.equipment}
    - Time per workout: ${preferences.time}
    
    Return JSON only.`;

    const response = await ai.models.generateContent({
        model: 'gemini-2.5-flash',
        contents: prompt,
        config: {
            responseMimeType: 'application/json',
            responseSchema: plansSchema,
        },
    });
    return JSON.parse(response.text);
}

// --- UI Interaction & DOM Manipulation ---

// Generic Sidebar Toggle
const toggleSidebar = () => {
    const sidebar = document.getElementById('sidebar');
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const mainContent = document.querySelector('.main-content');
    const width = window.innerWidth;

    if (width < 768) {
        // Mobile logic
        mobileSidebar.classList.toggle('-translate-x-full');
    } else {
        // Desktop logic
        if (sidebar.classList.contains('w-64')) {
            sidebar.classList.remove('w-64');
            sidebar.classList.add('w-20');
            mainContent.classList.remove('md:ml-64');
            mainContent.classList.add('md:ml-20');
            document.querySelectorAll('.sidebar-text').forEach(el => el.classList.add('hidden'));
        } else {
            sidebar.classList.remove('w-20');
            sidebar.classList.add('w-64');
            mainContent.classList.remove('md:ml-20');
            mainContent.classList.add('md:ml-64');
            document.querySelectorAll('.sidebar-text').forEach(el => el.classList.remove('hidden'));
        }
    }
};

// Chart Rendering Helpers (SVG)
function renderRadarChart(data, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    // Simple SVG Radar implementation
    const size = 300;
    const center = size / 2;
    const radius = size * 0.4;
    const subjects = ['Chest', 'Abs', 'Arms', 'Back', 'Legs'];
    const scores = [data.chest.score, data.abs.score, data.arms.score, data.back.score, data.legs.score];
    
    const angleSlice = (Math.PI * 2) / subjects.length;
    
    let points = "";
    const levels = 5;
    
    let svgContent = `<svg width="100%" height="100%" viewBox="0 0 ${size} ${size}" class="chart-svg">`;
    
    // Draw Grid
    for(let i=1; i<=levels; i++) {
        let levelPoints = "";
        const levelRadius = radius * (i / levels);
        for(let j=0; j<subjects.length; j++) {
            const x = center + levelRadius * Math.cos(j * angleSlice - Math.PI/2);
            const y = center + levelRadius * Math.sin(j * angleSlice - Math.PI/2);
            levelPoints += `${x},${y} `;
        }
        svgContent += `<polygon points="${levelPoints}" class="radar-grid" />`;
    }
    
    // Draw Axes & Labels
    for(let j=0; j<subjects.length; j++) {
        const x = center + radius * Math.cos(j * angleSlice - Math.PI/2);
        const y = center + radius * Math.sin(j * angleSlice - Math.PI/2);
        svgContent += `<line x1="${center}" y1="${center}" x2="${x}" y2="${y}" stroke="#4A4A4A" />`;
        
        // Labels
        const labelX = center + (radius + 20) * Math.cos(j * angleSlice - Math.PI/2);
        const labelY = center + (radius + 20) * Math.sin(j * angleSlice - Math.PI/2);
        svgContent += `<text x="${labelX}" y="${labelY}" text-anchor="middle" alignment-baseline="middle">${subjects[j]}</text>`;
    }

    // Draw Data
    for(let j=0; j<subjects.length; j++) {
        const val = scores[j] / 10; // normalize 1-10
        const x = center + (radius * val) * Math.cos(j * angleSlice - Math.PI/2);
        const y = center + (radius * val) * Math.sin(j * angleSlice - Math.PI/2);
        points += `${x},${y} `;
    }
    
    svgContent += `<polygon points="${points}" class="radar-polygon" />`;
    svgContent += `</svg>`;
    
    container.innerHTML = svgContent;
}

// Page Specific Initialization
document.addEventListener('DOMContentLoaded', () => {
    const page = document.body.getAttribute('data-page');
    
    // Bind Global Events
    const sidebarToggles = document.querySelectorAll('.sidebar-toggle');
    sidebarToggles.forEach(btn => btn.addEventListener('click', toggleSidebar));
    
    const mobileOverlay = document.getElementById('mobile-overlay');
    if(mobileOverlay) mobileOverlay.addEventListener('click', toggleSidebar);

    // Page Routing Logic
    if (page === 'home') {
        initHomePage();
    } else if (page === 'stats') {
        initStatsPage();
    } else if (page === 'history') {
        initHistoryPage();
    } else if (page === 'profile') {
        initProfilePage();
    }
});

// --- Home Page Logic ---
let selectedFiles = []; // Persist state within the module

function initHomePage() {
    const uploadView = document.getElementById('upload-view');
    const analyzingView = document.getElementById('analyzing-view');
    const resultsView = document.getElementById('results-view');
    const photoCountLabel = document.getElementById('photo-count');
    const previewContainer = document.getElementById('image-previews');
    const analyzeBtn = document.getElementById('analyze-btn');
    
    // Reset state on init
    selectedFiles = [];

    // Check if we have a current report to show (persist state between refreshes/nav)
    const report = state.get(STORAGE_KEYS.CURRENT_REPORT, null);
    if (report) {
        renderReport(report);
        uploadView.classList.add('hidden');
        resultsView.classList.remove('hidden');
    } else {
        uploadView.classList.remove('hidden');
        resultsView.classList.add('hidden');
    }

    // New Analysis / Reset
    document.querySelectorAll('.new-analysis-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            resultsView.classList.add('hidden');
            uploadView.classList.remove('hidden');
            state.set(STORAGE_KEYS.CURRENT_REPORT, null);
            // Reset files
            selectedFiles = [];
            updateFileUI();
        });
    });

    // File Upload Logic
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');

    if(dropZone) {
        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('active'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('active'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('active');
            addFiles(e.dataTransfer.files);
        });
        fileInput.addEventListener('change', (e) => addFiles(e.target.files));
    }

    function addFiles(files) {
        const incomingFiles = Array.from(files);
        const slotsRemaining = 3 - selectedFiles.length;

        if (slotsRemaining <= 0) {
            alert("You have already selected 3 photos. Please remove one to add another.");
            return;
        }

        if (incomingFiles.length > slotsRemaining) {
            alert(`You can only add ${slotsRemaining} more photo(s).`);
            // Add as many as fit
            const filesToAdd = incomingFiles.slice(0, slotsRemaining);
            selectedFiles = [...selectedFiles, ...filesToAdd];
        } else {
            selectedFiles = [...selectedFiles, ...incomingFiles];
        }
        
        updateFileUI();
    }

    // Expose remove function globally for inline onclick handlers (or attach events dynamically)
    window.removeFile = (index) => {
        selectedFiles.splice(index, 1);
        updateFileUI();
    }

    function updateFileUI() {
        previewContainer.innerHTML = '';
        
        selectedFiles.forEach((file, index) => {
            const div = document.createElement('div');
            div.className = 'relative group';
            div.innerHTML = `
                <img src="${URL.createObjectURL(file)}" class="w-full h-32 object-cover rounded-lg border border-gray-700" />
                <button onclick="removeFile(${index})" class="absolute top-1 right-1 bg-red-600 text-white rounded-full p-1 shadow-lg opacity-90 hover:opacity-100 transition-opacity">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            `;
            previewContainer.appendChild(div);
        });

        photoCountLabel.innerText = `${selectedFiles.length} / 3 Photos Selected`;
        photoCountLabel.classList.remove('hidden');

        if (selectedFiles.length === 3) {
            analyzeBtn.disabled = false;
            analyzeBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            analyzeBtn.innerHTML = "Analyze My Physique";
        } else {
            analyzeBtn.disabled = true;
            analyzeBtn.classList.add('opacity-50', 'cursor-not-allowed');
            analyzeBtn.innerHTML = `Select ${3 - selectedFiles.length} more photo(s)`;
        }
    }

    // Analyze Action
    analyzeBtn?.addEventListener('click', async () => {
        if (selectedFiles.length !== 3) return;

        uploadView.classList.add('hidden');
        analyzingView.classList.remove('hidden');

        // Gather preferences including new fields
        const prefs = {
            sex: document.getElementById('pref-sex').value,
            age: document.getElementById('pref-age').value,
            height: document.getElementById('pref-height').value,
            weight: document.getElementById('pref-weight').value,
            activity: document.getElementById('pref-activity').value,
            goal: document.getElementById('pref-goal').value,
            experience: document.getElementById('pref-experience').value,
            equipment: document.getElementById('pref-equipment').value,
            time: document.getElementById('pref-time').value,
            enablePose: document.getElementById('pref-pose-analysis').checked,
            showLandmarks: document.getElementById('pref-pose-landmarks').checked
        };

        try {
            const report = await analyzePhysique(selectedFiles, prefs);
            const plans = await generatePlans(report, prefs);
            
            // Save to storage
            state.set(STORAGE_KEYS.CURRENT_REPORT, report);
            state.set(STORAGE_KEYS.PLANS, plans);
            
            // Add to history
            const history = state.get(STORAGE_KEYS.HISTORY, []);
            history.unshift({
                date: new Date().toLocaleDateString(),
                report: report,
                plans: plans
            });
            state.set(STORAGE_KEYS.HISTORY, history);

            // Render
            renderReport(report, plans);
            
            analyzingView.classList.add('hidden');
            resultsView.classList.remove('hidden');
        } catch (error) {
            alert("Error analyzing: " + error.message);
            analyzingView.classList.add('hidden');
            uploadView.classList.remove('hidden');
        }
    });
}

function renderReport(report, plans = null) {
    if(!plans) plans = state.get(STORAGE_KEYS.PLANS, null);

    // Render Radar Chart
    renderRadarChart(report.muscleAnalysis, 'radar-chart-container');

    // Fill Text Data
    document.getElementById('overall-score').innerText = report.physiqueRating.overallScore + "/10";
    document.getElementById('summary-text').innerText = report.physiqueRating.summary;
    document.getElementById('posture-text').innerText = report.postureNotes;

    // Muscle Details
    const muscleContainer = document.getElementById('muscle-details-grid');
    muscleContainer.innerHTML = '';
    Object.entries(report.muscleAnalysis).forEach(([name, data]) => {
        muscleContainer.innerHTML += `
            <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                <h4 class="text-xl font-bold capitalize mb-3">${name}</h4>
                <p><strong class="text-green-400">Strengths:</strong> ${data.strengths}</p>
                <p class="mt-2"><strong class="text-yellow-400">Weaknesses:</strong> ${data.weaknesses}</p>
                <p class="mt-2 text-sm text-gray-400"><strong class="text-blue-400">Symmetry:</strong> ${data.symmetryNotes}</p>
            </div>
        `;
    });

    // Handle Tabs
    const tabs = {
        report: document.getElementById('tab-content-report'),
        workout: document.getElementById('tab-content-workout'),
        meal: document.getElementById('tab-content-meal')
    };
    
    // Render Workout
    if(plans) {
        const workoutContainer = document.getElementById('workout-plan-container');
        workoutContainer.innerHTML = plans.workoutPlan.plan.map(day => `
            <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800 mb-4">
                <div class="font-bold text-xl flex justify-between items-center mb-2">
                    <div><span class="text-green-400">${day.dayOfWeek}:</span> ${day.targetMuscle}</div>
                </div>
                <div class="text-gray-300 space-y-2">
                    <p><strong>Warm-up:</strong> ${day.warmup}</p>
                    <ul class="pl-4 border-l-2 border-gray-700 my-3">
                        ${day.exercises.map(ex => `<li><strong>${ex.name}</strong>: ${ex.sets} x ${ex.reps} (${ex.rest} rest)</li>`).join('')}
                    </ul>
                    <p><strong>Cooldown:</strong> ${day.cooldown}</p>
                </div>
            </div>
        `).join('');

        // Render Meal
        const mealContainer = document.getElementById('meal-guide-container');
        mealContainer.innerHTML = `
            <div class="text-center bg-gray-900/50 p-6 rounded-xl border border-gray-800 mb-6">
                 <div class="grid grid-cols-4 gap-4">
                    <div><p class="text-gray-400">Calories</p><p class="text-2xl font-bold text-green-400">${plans.mealGuide.dailyCalorieTarget}</p></div>
                    <div><p class="text-gray-400">Protein</p><p class="text-2xl font-bold">${plans.mealGuide.macros.protein}</p></div>
                    <div><p class="text-gray-400">Carbs</p><p class="text-2xl font-bold">${plans.mealGuide.macros.carbs}</p></div>
                    <div><p class="text-gray-400">Fats</p><p class="text-2xl font-bold">${plans.mealGuide.macros.fats}</p></div>
                 </div>
            </div>
            <div class="grid md:grid-cols-2 gap-6">
                ${plans.mealGuide.meals.map(meal => `
                    <div class="bg-gray-900/50 p-6 rounded-xl border border-gray-800">
                        <h4 class="text-xl font-bold mb-2">${meal.name}</h4>
                        <p class="text-gray-400 text-sm mb-2">${meal.notes}</p>
                        <ul class="list-disc list-inside text-gray-300 text-sm">
                            ${meal.ingredients.map(i => `<li>${i}</li>`).join('')}
                        </ul>
                    </div>
                `).join('')}
            </div>
        `;
    }

    // Tab Clicking Logic
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active', 'border-b-2', 'border-green-500', 'text-green-500'));
            e.currentTarget.classList.add('active', 'border-b-2', 'border-green-500', 'text-green-500');
            
            const target = e.currentTarget.dataset.tab;
            Object.values(tabs).forEach(el => el.classList.add('hidden'));
            tabs[target].classList.remove('hidden');
        });
    });
}

// --- Stats Page Logic ---
function initStatsPage() {
    const report = state.get(STORAGE_KEYS.CURRENT_REPORT, null);
    if (!report) {
        document.getElementById('no-stats-view').classList.remove('hidden');
        document.getElementById('stats-content').classList.add('hidden');
        return;
    }

    renderRadarChart(report.muscleAnalysis, 'stats-radar-chart');
    document.getElementById('stat-overall-score').innerText = report.physiqueRating.overallScore;
    
    // Find strongest/weakest
    let strongest = {name: '', score: -1};
    let weakest = {name: '', score: 11};
    
    Object.entries(report.muscleAnalysis).forEach(([name, data]) => {
        if(data.score > strongest.score) strongest = {name, score: data.score};
        if(data.score < weakest.score) weakest = {name, score: data.score};
    });
    
    document.getElementById('stat-strongest-name').innerText = strongest.name;
    document.getElementById('stat-weakest-name').innerText = weakest.name;
}

// --- History Page Logic ---
function initHistoryPage() {
    const history = state.get(STORAGE_KEYS.HISTORY, []);
    const container = document.getElementById('history-list');
    
    if(history.length === 0) {
        container.innerHTML = `<div class="text-center text-gray-400 py-10">No analyses found. Start your first scan!</div>`;
        return;
    }

    container.innerHTML = history.map((item, index) => `
        <div class="w-full text-left bg-gray-900/50 p-4 rounded-xl border border-gray-800 mb-4">
            <div class="flex items-center gap-4">
                <div class="bg-gray-800 rounded-lg w-20 h-20 flex-shrink-0 flex items-center justify-center">
                    <svg class="w-10 h-10 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                </div>
                <div class="flex-1">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="font-bold text-lg">${item.date}</p>
                            <div class="mt-1 px-3 py-1 bg-green-500/10 text-green-400 font-bold text-sm rounded-full inline-block">
                                Score: ${item.report.physiqueRating.overallScore}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

// --- Profile Page Logic ---
function initProfilePage() {
    const prefs = state.get(STORAGE_KEYS.PREFERENCES, defaultPrefs);
    
    // Populate fields
    const ids = ['goal', 'experience', 'equipment', 'time'];
    ids.forEach(id => {
        const el = document.getElementById(`prof-${id}`);
        if(el) el.value = prefs[id];
    });

    // Save handler
    document.getElementById('save-profile-btn').addEventListener('click', () => {
        const newPrefs = { ...prefs };
        ids.forEach(id => {
            const el = document.getElementById(`prof-${id}`);
            if(el) newPrefs[id] = el.value;
        });
        state.set(STORAGE_KEYS.PREFERENCES, newPrefs);
        alert("Preferences Saved!");
    });
}
