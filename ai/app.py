import os
import json
import pandas as pd
from flask import Flask, request, jsonify
from flask_cors import CORS
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.naive_bayes import MultinomialNB
import pickle

app = Flask(__name__)
CORS(app)  # Enable CORS for all routes

# --- Global Variables ---
vectorizer = None
model = None
med_db = []
DATASET_PATH = "ai_triage_queue_dataset_200.xlsx"
FEEDBACK_PATH = "ai_feedback_data.csv"
MED_DB_PATH = "med_database.json"

# --- 1. Load Medicine Database ---
def load_med_db():
    global med_db
    if os.path.exists(MED_DB_PATH):
        with open(MED_DB_PATH, 'r') as f:
            med_db = json.load(f)
        print(f"Loaded {len(med_db)} medicines from database.")
    else:
        print("Warning: med_database.json not found.")

# --- 2. Mock Data Generator (Fallback) ---
def create_mock_dataset():
    data = {
        "Medical_History": ["None", "Diabetes", "Asthma", "Heart Condition", "None", "Migraine", "None", "Smoking", "High BP"],
        "Symptoms": [
            "mild headache", 
            "thirst and frequent urination", 
            "wheezing breath shortage", 
            "severe chest pain radiating to left arm", 
            "cut on finger bleeding", 
            "throbbing headache sensitivity to light", 
            "fever and cough", 
            "chronic cough night sweats", 
            "severe headache blurred vision"
        ],
        "Keyword_Symptoms": [
            "headache", "thirst, urination", "wheezing", "chest pain", "cut, bleeding", "headache, light", "fever", "cough", "headache, vision"
        ],
        "AI_Urgency": ["Low", "Medium", "High", "Critical", "Low", "Medium", "Medium", "High", "Critical"],
        "Disease_Label": ["Tension Headache", "Diabetes Type 2", "Asthma Attack", "Myocardial Infarction", "Laceration", "Migraine", "Viral Flu", "Tuberculosis", "Hypertensive Crisis"]
    }
    return pd.DataFrame(data)

# --- 3. Training Logic (Continuous Learning) ---
def train_model():
    global vectorizer, model
    
    # Load Base Dataset
    if os.path.exists(DATASET_PATH):
        try:
            df = pd.read_excel(DATASET_PATH)
            # Normalize column names if needed
            if "Disease_Label" not in df.columns:
               # Map urgency to a proxy label if real label missing (just for demo)
               df["Disease_Label"] = df["AI_Urgency"] + " Risk Condition" 
        except Exception as e:
            print(f"Error loading base dataset: {e}")
            df = create_mock_dataset()
    else:
        df = create_mock_dataset()

    # Load Feedback Data (New Learning)
    if os.path.exists(FEEDBACK_PATH):
        try:
            df_new = pd.read_csv(FEEDBACK_PATH)
            if not df_new.empty:
                print(f"Integrating {len(df_new)} new learning samples...")
                df = pd.concat([df, df_new], ignore_index=True)
        except Exception as e:
            print(f"Error loading feedback data: {e}")

    # Preprocessing
    df = df.fillna("")
    df["Text"] = (
        df["Medical_History"].astype(str) + " " +
        df["Symptoms"].astype(str) + " " +
        df["Keyword_Symptoms"].astype(str)
    )

    # Vectorization
    print(f"Training model on {len(df)} records...")
    vectorizer = TfidfVectorizer()
    X_vector = vectorizer.fit_transform(df["Text"])
    y = df["Disease_Label"] # predicting specific disease/condition now

    # Training
    model = MultinomialNB()
    model.fit(X_vector, y)
    print("Model training complete.")

# --- 4. Prediction Logic ---
@app.route('/predict', methods=['POST'])
def predict():
    if not model or not vectorizer:
        return jsonify({"error": "Model not trained yet."}), 500

    data = request.json
    if not data:
        return jsonify({"error": "No JSON data provided"}), 400

    history = data.get("history", "")
    symptoms = data.get("symptoms", "")
    vitals = data.get("vitals", {}) # Expecting dict like {'heart_rate': 80, ...}
    
    # A. Vitals-Based Pre-Diagnosis (Rule Engine)
    vital_flags = []
    
    # Parsing vitals if they come as string or incomplete
    if isinstance(vitals, str):
        try: vitals = json.loads(vitals)
        except: vitals = {}
    # Fix: PHP encodes empty arrays as [] (list), not {} (dict)
    if not isinstance(vitals, dict):
        vitals = {}
        
    hr = float(vitals.get('heart_rate', 0))
    bp_sys = float(vitals.get('bp_systolic', 0))
    temp = float(vitals.get('temperature', 0))
    glucose = float(vitals.get('glucose', 0))
    
    pre_diagnosis = []

    if bp_sys > 180:
        pre_diagnosis.append("Hypertensive Crisis (Critical)")
        vital_flags.append("critical BP")
    elif bp_sys > 140:
        pre_diagnosis.append("Hypertension")
        vital_flags.append("high BP")
        
    if hr > 120:
        pre_diagnosis.append("Tachycardia / Possible Infection")
        vital_flags.append("high heart rate")
    elif hr < 50 and hr > 0:
        pre_diagnosis.append("Bradycardia")

    if temp > 102:
        pre_diagnosis.append("Severe Infection / Sepsis Risk")
        vital_flags.append("high fever")
    elif temp > 99.5:
        pre_diagnosis.append("Viral Fever")
        
    if glucose > 250:
        pre_diagnosis.append("Diabetic Ketoacidosis Risk")
        vital_flags.append("critical sugar")
    elif glucose > 140:
        pre_diagnosis.append("Hyperglycemia")

    # B. AI Prediction (ML)
    # Combine everything for the model
    text_input = f"{history} {symptoms} {' '.join(vital_flags)}"
    text_vec = vectorizer.transform([text_input])

    # Get Probabilities
    probs = model.predict_proba(text_vec)[0]
    classes = model.classes_
    
    # Sort by probability
    sorted_probs = sorted(zip(classes, probs), key=lambda x: x[1], reverse=True)
    top_3 = [{"condition": c, "probability": round(p * 100, 1)} for c, p in sorted_probs[:3] if p > 0.05]

    # --- ENHANCED RULE-BASED OVERRIDES (Prioritize specific keywords) ---
    s_lower = symptoms.lower()
    override_condition = None
    override_urgency = "Low"
    
    if "respiratory effort" in s_lower or "shortness of breath" in s_lower or "pneumonia" in s_lower:
        override_condition = "Severe Respiratory Distress"
        override_urgency = "Critical"
    elif "chest pain" in s_lower or "myocardial" in s_lower or "heart attack" in s_lower:
        override_condition = "Myocardial Infarction Risk"
        override_urgency = "Critical"
    elif "stroke" in s_lower or "slurred speech" in s_lower:
        override_condition = "Stroke Risk"
        override_urgency = "Critical"
        
    # Smart fallback / Override
    primary_prediction = top_3[0]['condition'] if top_3 else "General Assessment Needed"
    
    # Apply override if found
    if override_condition:
        primary_prediction = override_condition
        # Insert at top of probability list visually
        top_3.insert(0, {"condition": override_condition, "probability": 99.9})

    if not symptoms.strip() and pre_diagnosis:
        # If doctor hasn't typed symptoms but vitals are abnormal, prioritize vitals
        primary_prediction = pre_diagnosis[0]
        top_3.insert(0, {"condition": pre_diagnosis[0], "probability": 95.0})

    # Urgency Logic (Simplified)
    urgency = "Low"
    if override_urgency == "Critical":
        urgency = "Critical"
    elif "Critical" in primary_prediction or "Attack" in primary_prediction or "Sepsis" in primary_prediction:
        urgency = "Critical"
    elif "Severe" in primary_prediction or "High" in primary_prediction or bp_sys > 160 or temp > 103:
        urgency = "High"
    elif "Medium" in primary_prediction or temp > 100:
        urgency = "Medium"

    # --- Medication Recommendation Logic ---
    suggested_meds = []
    
    # Simple Knowledge Base (Condition -> Meds)
    # in a real system this would be a separate ML model or database query
    med_knowledge = {
        "Fever": ["Paracetamol 500mg", "Ibuprofen 400mg"],
        "Pain": ["Ibuprofen 400mg", "Diclofenac 50mg"],
        "Headache": ["Paracetamol 500mg", "Aspirin 300mg"],
        "Migraine": ["Sumatriptan 50mg", "Naproxen 500mg"],
        "Infection": ["Amoxicillin 500mg", "Azithromycin 500mg"],
        "Hypertension": ["Amlodipine 5mg", "Lisinopril 10mg"],
        "Diabetes": ["Metformin 500mg", "Glimepiride 1mg"],
        "Asthma": ["Salbutamol Inhaler", "Budesonide Inhaler"],
        "Respiratory Distress": ["Oxygen Therapy", "Hydrocortisone IV"],
        "Pneumonia": ["Azithromycin 500mg", "Augmentin 625mg"],
        "Acidity": ["Pantoprazole 40mg", "Omeprazole 20mg"],
        "Myocardial Infarction": ["Aspirin 300mg (Stat)", "Clopidogrel 300mg (Stat)"]
    }
    
    # 1. Check Condition Match
    for condition, meds in med_knowledge.items():
        if condition.lower() in primary_prediction.lower():
            suggested_meds.extend(meds)
            
    # 2. Check Keyword Overrides if empty
    if not suggested_meds:
        s_lower = symptoms.lower()
        if "fever" in s_lower: suggested_meds.extend(med_knowledge["Fever"])
        if "pain" in s_lower: suggested_meds.extend(med_knowledge["Pain"])
        if "cough" in s_lower: suggested_meds.extend(["Dextromethorphan Syrup"])
        if "vomiting" in s_lower: suggested_meds.extend(["Ondansetron 4mg"])

    # Returns unique meds
    suggested_meds = list(set(suggested_meds))

    return jsonify({
        "disease": primary_prediction,
        "urgency": urgency,
        "probabilities": top_3,
        "vitals_analysis": pre_diagnosis,
        "specialization": "General Medicine",
        "suggested_medication": suggested_meds
    })

# --- 5. Continuous Learning Endpoint ---
@app.route('/learn', methods=['POST'])
def learn():
    data = request.json
    # Expects: history, symptoms, diagnosed_condition, urgency
    
    required = ["history", "symptoms", "diagnosis"]
    if not all(k in data for k in required):
        return jsonify({"error": "Missing data"}), 400
        
    new_record = {
        "Medical_History": data['history'],
        "Symptoms": data['symptoms'],
        "Keyword_Symptoms": data.get('keywords', data['symptoms']), # simple fallback
        "AI_Urgency": data.get('urgency', 'Medium'),
        "Disease_Label": data['diagnosis']
    }
    
    # Append to CSV
    try:
        new_df = pd.DataFrame([new_record])
        if not os.path.exists(FEEDBACK_PATH):
            new_df.to_csv(FEEDBACK_PATH, index=False)
        else:
            new_df.to_csv(FEEDBACK_PATH, mode='a', header=False, index=False)
            
        # Trigger Retrain
        train_model()
        
        return jsonify({"status": "success", "message": "Knowledge updated."})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

# --- 6. Smart Medicine Alternative ---
@app.route('/suggest_alternative', methods=['POST'])
def suggest_alternative():
    data = request.json
    if not data or 'medicine' not in data:
        return jsonify({"error": "No medicine provided"}), 400
        
    med_name = data['medicine'].lower().strip()
    
    # Find the medication in our DB
    found_med = None
    for m in med_db:
        if m['name'].lower() == med_name or med_name in [b.lower() for b in m['brand_names']]:
            found_med = m
            break
            
    if not found_med:
        # Fallback to old simple dict if not in JSON (optional)
        return jsonify({
            "suggested_alternative": f"Generic {med_name.capitalize()}",
            "reason": "Exact match not found in smart DB. Suggesting generic.",
            "dosage": "As per prescription",
            "active_ingredients": []
        })

    # Look for substitutes with OVERLAPPING active ingredients
    substitutes = []
    target_ingredients = set(found_med['active_ingredients'])
    
    for m in med_db:
        if m['name'] == found_med['name']: continue # Skip self
        
        # Check ingredient overlap
        current_ingredients = set(m['active_ingredients'])
        if target_ingredients & current_ingredients: # Intersection exists
            substitutes.append(m)

    if substitutes:
        # Pick the best one (same form preferred)
        best_sub = substitutes[0] # Default first
        for s in substitutes:
            if s['dosage_form'] == found_med['dosage_form']:
                best_sub = s
                break
        
        return jsonify({
            "original_medicine": found_med['name'],
            "suggested_alternative": best_sub['brand_names'][0] if best_sub['brand_names'] else best_sub['name'],
            "reason": f"Same formula: {', '.join(best_sub['active_ingredients'])}",
            "dosage": best_sub['strength'],
            "active_ingredients": best_sub['active_ingredients']
        })
    else:
         return jsonify({
            "suggested_alternative": "No direct formula match",
            "reason": "Consult Doctor for alternative class.",
            "dosage": "N/A"
        })

if __name__ == '__main__':
    load_med_db()
    train_model()
    app.run(host='0.0.0.0', port=5001, debug=True, use_reloader=False)
