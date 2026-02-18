import os
import pandas as pd
from flask import Flask, request, jsonify
from flask_cors import CORS
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.naive_bayes import MultinomialNB
import pickle

app = Flask(__name__)
CORS(app)  # Enable CORS for all routes

# --- Global Model Variables ---
vectorizer = None
model = None

# --- Mock Data Generator (Fallback) ---
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
        "AI_Urgency": ["Low", "Medium", "High", "Critical", "Low", "Medium", "Medium", "High", "Critical"]
    }
    return pd.DataFrame(data)

# --- Training Logic ---
def train_model():
    global vectorizer, model
    
    dataset_path = "ai_triage_queue_dataset_200.xlsx"
    
    if os.path.exists(dataset_path):
        print(f"Loading dataset from {dataset_path}...")
        try:
            df = pd.read_excel(dataset_path)
            # Ensure columns exist
            required_cols = ["Medical_History", "Symptoms", "Keyword_Symptoms", "AI_Urgency"]
            if not all(col in df.columns for col in required_cols):
                print("Dataset missing required columns. Using mock data.")
                df = create_mock_dataset()
        except Exception as e:
            print(f"Error loading dataset: {e}. Using mock data.")
            df = create_mock_dataset()
    else:
        print("Dataset not found. Using MOCK data for demonstration.")
        df = create_mock_dataset()

    # Preprocessing
    df = df.fillna("")
    df["Text"] = (
        df["Medical_History"].astype(str) + " " +
        df["Symptoms"].astype(str) + " " +
        df["Keyword_Symptoms"].astype(str)
    )

    # Vectorization
    print("Vectorizing text...")
    vectorizer = TfidfVectorizer()
    X_vector = vectorizer.fit_transform(df["Text"])
    y = df["AI_Urgency"]

    # Training
    print("Training Naive Bayes model...")
    model = MultinomialNB()
    model.fit(X_vector, y)
    print("Model training complete.")

# --- Rule-Based Disease Prediction ---
def predict_disease_rule_based(symptoms):
    s = symptoms.lower()
    
    # Map to existing specializations: 
    # General Medicine, Diagnostic Medicine, General Practice, Cardiology
    
    if "chest pain" in s: 
        return "Heart Disease/Attack", "Cardiology"
    if "severe headache" in s or "blurred vision" in s: 
        return "Neurological Issue", "General Medicine" # Or refer to specialist if available
    if "thirst" in s and "urination" in s: 
        return "Diabetes", "General Medicine"
    if "cough" in s and "night sweats" in s: 
        return "Tuberculosis", "General Medicine"
    if "wheezing" in s: 
        return "Asthma", "General Medicine"
    if "headache" in s: 
        return "Migraine", "General Medicine"
    if "fever" in s: 
        return "Viral Infection/Flu", "General Medicine"
    if "cut" in s or "bleeding" in s: 
        return "Trauma/Injury", "General Practice"
    if "stomach" in s or "pain" in s:
        return "Gastric Issue", "General Medicine"
        
    return "General Illness", "General Practice"

# --- API Endpoints ---
@app.route('/status', methods=['GET'])
def status():
    return jsonify({"status": "running", "model_trained": model is not None})

@app.route('/predict', methods=['POST'])
def predict():
    if not model or not vectorizer:
        return jsonify({"error": "Model not trained yet."}), 500

    data = request.json
    if not data:
        return jsonify({"error": "No JSON data provided"}), 400

    history = data.get("history", "")
    symptoms = data.get("symptoms", "")
    keywords = data.get("keywords", "")

    # Combine text for model input
    text = f"{history} {symptoms} {keywords}"
    text_vec = vectorizer.transform([text])

    # Predict Urgency (ML)
    urgency = model.predict(text_vec)[0]

    # Predict Disease (Rule-Based + placeholder)
    disease, specialization = predict_disease_rule_based(symptoms)

    return jsonify({
        "urgency": urgency,
        "disease": disease,
        "specialization": specialization,
        "raw_input": text
    })

# --- Main Entry ---
if __name__ == '__main__':
    train_model()
    # Run on port 5001
    app.run(host='0.0.0.0', port=5001, debug=True)
