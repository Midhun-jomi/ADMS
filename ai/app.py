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
# Ensure path is relative to script location
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DATASET_PATH = os.path.join(BASE_DIR, "ai_triage_queue_dataset_200.xlsx")
FEEDBACK_PATH = os.path.join(BASE_DIR, "ai_feedback_data.csv")
MED_DB_PATH = os.path.join(BASE_DIR, "med_database.json")

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
        "Medical_History": ["None", "Diabetes", "Asthma", "Heart Condition", "Migraine", "Gastritis", "None"],
        "Symptoms": [
            "mild headache", "thirst and urination", "wheezing", "chest pain", "stomach pain", "fever", "cough"
        ],
        "Keyword_Symptoms": [
            "headache", "thirst", "wheezing", "chest pain", "stomach", "fever", "cough"
        ],
        "AI_Urgency": ["Low", "Medium", "High", "Critical", "Low", "Medium", "Medium"],
        "Disease_Label": ["Headache", "Diabetes", "Asthma", "Heart Issue", "Acidity", "Viral", "Cold"]
    }
    return pd.DataFrame(data)

# --- 3. Training Logic (Continuous Learning) ---
def train_model():
    global vectorizer, model

    # Path logic
    base_file = "ai_triage_queue_dataset_200.xlsx"
    path_alternatives = [base_file, os.path.join("ai", base_file), os.path.join("..", base_file)]

    df = None
    for path in path_alternatives:
        if os.path.exists(path):
            try:
                print(f"Loading dataset from: {path}")
                df = pd.read_excel(path)
                break
            except Exception as e:
                print(f"Error reading {path}: {e}")

    if df is None:
        print("Warning: Dataset not found. Using fallback mock data.")
        df = create_mock_dataset()

    # Data Cleaning
    df = df.fillna("")

    # Feature Engineering (Matching untitled1.py)
    for col in ["Medical_History", "Symptoms", "Keyword_Symptoms"]:
        if col not in df.columns:
            df[col] = ""

    df["Text"] = (
        df["Medical_History"].astype(str) + " " +
        df["Symptoms"].astype(str) + " " +
        df["Keyword_Symptoms"].astype(str)
    )

    # Target Label: AI_Urgency (Proper Training)
    if "AI_Urgency" not in df.columns:
        df["AI_Urgency"] = "Medium"

    y = df["AI_Urgency"]

    # Training
    print(f"Training urgency model on {len(df)} records...")
    vectorizer = TfidfVectorizer(stop_words='english')
    X_vector = vectorizer.fit_transform(df["Text"])

    model = MultinomialNB()
    model.fit(X_vector, y)
    print("Urgency model training complete.")

# --- 4. Disease Prediction Engine (Rule-based) ---
def engine_predict_disease(symptoms, full_history=""):
    s = symptoms.lower().strip()
    h = full_history.lower().strip()
    combined = s + " " + h

    disease_map = {
        # Cardiac / Vascular
        "heart attack": "Possible Cardiac Condition",
        "cardiac arrest": "Possible Cardiac Condition",
        "myocardial infarction": "Possible Cardiac Condition",
        "angina": "Angina Pectoris / Chest Pain",
        "heart failure": "Congestive Heart Failure",
        "congestive heart": "Congestive Heart Failure",
        "pericarditis": "Pericarditis / Heart Inflammation",
        "cardiomyopathy": "Cardiomyopathy / Heart Muscle Disease",
        "atrial fibrillation": "Cardiac Arrhythmia / Atrial Fibrillation",
        "arrhythmia": "Cardiac Arrhythmia",
        "hypertension": "Hypertension / High Blood Pressure",
        "high blood pressure": "Hypertension / High Blood Pressure",
        "low blood pressure": "Hypotension / Low Blood Pressure",
        "hypotension": "Hypotension / Low Blood Pressure",
        "cholesterol": "Hyperlipidemia / High Cholesterol",
        "hyperlipidemia": "Hyperlipidemia / High Cholesterol",
        "dvt": "Deep Vein Thrombosis (DVT)",
        "deep vein thrombosis": "Deep Vein Thrombosis (DVT)",
        "varicose": "Varicose Veins",
        "peripheral artery": "Peripheral Artery Disease",
        "aortic aneurysm": "Aortic Aneurysm",
        "mitral valve": "Mitral Valve Disease",
        # Endocrine / Metabolic
        "diabetes": "Diabetes Mellitus",
        "diabetic": "Diabetes Mellitus",
        "type 1 diabetes": "Type 1 Diabetes Mellitus",
        "type 2 diabetes": "Type 2 Diabetes Mellitus",
        "gestational diabetes": "Gestational Diabetes",
        "thyroid": "Thyroid Disorder",
        "hypothyroid": "Hypothyroidism",
        "hyperthyroid": "Hyperthyroidism",
        "goiter": "Goitre / Thyroid Enlargement",
        "adrenal": "Adrenal Gland Disorder",
        "cushing": "Cushing's Syndrome",
        "addison": "Addison's Disease",
        "obesity": "Obesity / Metabolic Disorder",
        "metabolic syndrome": "Metabolic Syndrome",
        "pcos": "PCOS / Hormonal Disorder",
        "pcod": "PCOS / Hormonal Disorder",
        "gout": "Gout / Uric Acid Disorder",
        "hyperuricemia": "Hyperuricemia / Gout",
        # Respiratory
        "asthma": "Asthma / Respiratory Distress",
        "tuberculosis": "Tuberculosis (TB)",
        "tb": "Tuberculosis (TB)",
        "pneumonia": "Pneumonia / Lung Infection",
        "bronchitis": "Bronchitis / Airway Inflammation",
        "copd": "COPD / Chronic Lung Disease",
        "emphysema": "Emphysema / COPD",
        "pulmonary embolism": "Pulmonary Embolism",
        "pulmonary fibrosis": "Pulmonary Fibrosis",
        "pleurisy": "Pleuritis / Pleurisy",
        "pleuritis": "Pleuritis / Pleurisy",
        "sleep apnea": "Sleep Apnoea / Breathing Disorder",
        "cystic fibrosis": "Cystic Fibrosis",
        "whooping cough": "Whooping Cough / Pertussis",
        "pertussis": "Whooping Cough / Pertussis",
        "sinusitis": "Sinusitis / Nasal Congestion",
        "sinus": "Sinusitis / Nasal Congestion",
        "rhinitis": "Allergic Rhinitis / Hay Fever",
        "hay fever": "Allergic Rhinitis / Hay Fever",
        "tonsillitis": "Tonsillitis / Throat Infection",
        "tonsil": "Tonsillitis / Throat Infection",
        "laryngitis": "Laryngitis / Voice Loss",
        "nasal polyp": "Nasal Polyp",
        "deviated septum": "Deviated Nasal Septum",
        "adenoid": "Adenoid Hypertrophy",
        # Gastrointestinal
        "gastritis": "Gastritis / Stomach Inflammation",
        "gastroenteritis": "Gastroenteritis / Stomach Infection",
        "appendicitis": "Potential Appendicitis",
        "ulcer": "Peptic Ulcer Disease",
        "peptic ulcer": "Peptic Ulcer Disease",
        "ibs": "Irritable Bowel Syndrome",
        "irritable bowel": "Irritable Bowel Syndrome",
        "colitis": "Colitis / Inflammatory Bowel Disease",
        "crohn": "Crohn's Disease",
        "inflammatory bowel": "Inflammatory Bowel Disease",
        "diverticulitis": "Diverticulitis / Bowel Condition",
        "pancreatitis": "Pancreatitis / Pancreas Inflammation",
        "gallstone": "Gallstones / Biliary Colic",
        "cholecystitis": "Cholecystitis / Gallbladder Infection",
        "jaundice": "Jaundice / Liver Condition",
        "hepatitis": "Hepatitis / Liver Infection",
        "cirrhosis": "Liver Cirrhosis",
        "fatty liver": "Non-Alcoholic Fatty Liver Disease",
        "nafld": "Non-Alcoholic Fatty Liver Disease",
        "hernia": "Hernia",
        "piles": "Piles / Haemorrhoids",
        "haemorrhoid": "Piles / Haemorrhoids",
        "hemorrhoid": "Piles / Haemorrhoids",
        "anal fissure": "Anal Fissure",
        "fistula": "Anal Fistula",
        "celiac": "Coeliac / Gluten Intolerance",
        "constipation": "Chronic Constipation",
        "diarrhea": "Diarrhoea / GI Infection",
        "achalasia": "Achalasia / Oesophageal Disorder",
        "gerd": "GERD / Acid Reflux",
        "acid reflux": "GERD / Acid Reflux",
        "lactose": "Lactose Intolerance",
        # Neurological
        "migraine": "Migraine / Severe Headache",
        "epilepsy": "Epilepsy / Seizure Disorder",
        "seizure": "Epilepsy / Seizure Disorder",
        "stroke": "Stroke / Cerebrovascular Accident",
        "tia": "TIA / Mini Stroke",
        "transient ischemic": "TIA / Mini Stroke",
        "paralysis": "Paralysis / Neurological Condition",
        "parkinson": "Parkinson's Disease",
        "alzheimer": "Alzheimer's Disease / Dementia",
        "dementia": "Dementia / Cognitive Decline",
        "vertigo": "Vertigo / Inner Ear Disorder",
        "meningitis": "Meningitis / Brain Infection",
        "encephalitis": "Encephalitis / Brain Inflammation",
        "bell's palsy": "Bell's Palsy / Facial Nerve Palsy",
        "bells palsy": "Bell's Palsy / Facial Nerve Palsy",
        "facial palsy": "Bell's Palsy / Facial Nerve Palsy",
        "multiple sclerosis": "Multiple Sclerosis",
        "ms ": "Multiple Sclerosis",
        "neuropathy": "Peripheral Neuropathy",
        "trigeminal": "Trigeminal Neuralgia",
        "cerebral palsy": "Cerebral Palsy",
        "guillain": "Guillain-Barré Syndrome",
        "motor neuron": "Motor Neuron Disease",
        "als": "Amyotrophic Lateral Sclerosis (ALS)",
        "hydrocephalus": "Hydrocephalus",
        "meniere": "Ménière's Disease",
        # Musculoskeletal
        "arthritis": "Arthritis / Joint Inflammation",
        "rheumatoid": "Rheumatoid Arthritis",
        "osteoarthritis": "Osteoarthritis / Degenerative Joint Disease",
        "osteoporosis": "Osteoporosis / Bone Weakness",
        "sciatica": "Sciatica / Nerve Pain",
        "slip disc": "Slipped Disc / Spinal Condition",
        "slipped disc": "Slipped Disc / Spinal Condition",
        "herniated disc": "Herniated Disc / Spinal Condition",
        "spondylitis": "Spondylitis / Spinal Inflammation",
        "spondylosis": "Spondylosis / Spinal Degeneration",
        "fracture": "Bone Fracture / Orthopedic Injury",
        "bursitis": "Bursitis / Joint Inflammation",
        "tendinitis": "Tendinitis / Tendon Inflammation",
        "tendonitis": "Tendinitis / Tendon Inflammation",
        "carpal tunnel": "Carpal Tunnel Syndrome",
        "rotator cuff": "Rotator Cuff Injury",
        "plantar fasciitis": "Plantar Fasciitis / Heel Pain",
        "fibromyalgia": "Fibromyalgia / Chronic Pain Syndrome",
        "myositis": "Myositis / Muscle Inflammation",
        "frozen shoulder": "Frozen Shoulder / Adhesive Capsulitis",
        "tennis elbow": "Tennis Elbow / Lateral Epicondylitis",
        "golfer elbow": "Golfer's Elbow / Medial Epicondylitis",
        "ligament tear": "Ligament Injury / ACL Tear",
        "acl": "ACL Tear / Knee Ligament Injury",
        "meniscus": "Meniscus Tear / Knee Injury",
        # Dermatology
        "psoriasis": "Psoriasis / Skin Condition",
        "eczema": "Eczema / Atopic Dermatitis",
        "atopic dermatitis": "Eczema / Atopic Dermatitis",
        "fungal": "Fungal Skin Infection",
        "ringworm": "Ringworm / Tinea Infection",
        "tinea": "Tinea / Fungal Infection",
        "allerg": "Allergic Reaction / Dermatitis",
        "urticaria": "Urticaria / Hives",
        "hives": "Urticaria / Hives",
        "acne": "Acne Vulgaris / Skin Condition",
        "vitiligo": "Vitiligo / Skin Pigmentation Disorder",
        "shingles": "Herpes Zoster / Shingles",
        "herpes zoster": "Herpes Zoster / Shingles",
        "melasma": "Melasma / Skin Pigmentation",
        "seborrhea": "Seborrheic Dermatitis",
        "alopecia": "Alopecia / Hair Loss",
        "hair loss": "Alopecia / Hair Loss",
        "cellulitis": "Cellulitis / Skin Infection",
        "impetigo": "Impetigo / Bacterial Skin Infection",
        "wart": "Warts / HPV Skin Lesion",
        "scabies": "Scabies / Mite Infestation",
        "rosacea": "Rosacea / Chronic Skin Redness",
        "contact dermatitis": "Contact Dermatitis",
        "drug rash": "Drug-Induced Rash",
        "ichthyosis": "Ichthyosis / Dry Scaly Skin",
        # Mental Health
        "depression": "Depression / Mood Disorder",
        "anxiety": "Anxiety / Stress Disorder",
        "insomnia": "Insomnia / Sleep Disorder",
        "ocd": "Obsessive-Compulsive Disorder (OCD)",
        "obsessive": "Obsessive-Compulsive Disorder (OCD)",
        "ptsd": "Post-Traumatic Stress Disorder (PTSD)",
        "trauma": "PTSD / Trauma Disorder",
        "bipolar": "Bipolar Disorder",
        "schizophrenia": "Schizophrenia / Psychotic Disorder",
        "psychosis": "Psychosis / Mental Health Crisis",
        "adhd": "ADHD / Attention Deficit Disorder",
        "attention deficit": "ADHD / Attention Deficit Disorder",
        "panic attack": "Panic Disorder",
        "panic disorder": "Panic Disorder",
        "eating disorder": "Eating Disorder",
        "anorexia": "Anorexia Nervosa",
        "bulimia": "Bulimia Nervosa",
        "autism": "Autism Spectrum Disorder",
        "phobia": "Phobia / Anxiety Disorder",
        "social anxiety": "Social Anxiety Disorder",
        "burnout": "Burnout / Stress Disorder",
        # Urological / Renal
        "kidney stone": "Kidney Stones / Renal Calculi",
        "renal calculi": "Kidney Stones / Renal Calculi",
        "nephrolithiasis": "Kidney Stones / Renal Calculi",
        "uti": "Urinary Tract Infection",
        "urinary infection": "Urinary Tract Infection",
        "urinary tract": "Urinary Tract Infection",
        "renal": "Renal / Kidney Condition",
        "kidney failure": "Chronic Kidney Disease / Renal Failure",
        "chronic kidney": "Chronic Kidney Disease",
        "ckd": "Chronic Kidney Disease",
        "nephrotic": "Nephrotic Syndrome",
        "nephritis": "Nephritis / Kidney Inflammation",
        "glomerulonephritis": "Glomerulonephritis",
        "hydronephrosis": "Hydronephrosis",
        "prostatitis": "Prostatitis / Prostate Infection",
        "prostate": "Prostate Condition",
        "bph": "Benign Prostatic Hyperplasia (BPH)",
        "benign prostatic": "Benign Prostatic Hyperplasia (BPH)",
        "incontinence": "Urinary Incontinence",
        "overactive bladder": "Overactive Bladder",
        "cystitis": "Cystitis / Bladder Infection",
        # Ophthalmology
        "cataract": "Cataract / Eye Condition",
        "glaucoma": "Glaucoma / Eye Condition",
        "conjunctivitis": "Conjunctivitis / Pink Eye",
        "retinal detachment": "Retinal Detachment",
        "macular degeneration": "Macular Degeneration",
        "dry eye": "Dry Eye Syndrome",
        "uveitis": "Uveitis / Eye Inflammation",
        "strabismus": "Strabismus / Squint",
        "squint": "Strabismus / Squint",
        "keratoconus": "Keratoconus / Corneal Disorder",
        "blepharitis": "Blepharitis / Eyelid Inflammation",
        "pterygium": "Pterygium / Eye Growth",
        "diabetic retinopathy": "Diabetic Retinopathy",
        "lazy eye": "Amblyopia / Lazy Eye",
        "amblyopia": "Amblyopia / Lazy Eye",
        # ENT
        "otitis": "Otitis / Ear Infection",
        "ear infection": "Otitis / Ear Infection",
        "tinnitus": "Tinnitus / Ringing in Ears",
        "hearing loss": "Hearing Loss / Auditory Disorder",
        "deafness": "Hearing Loss / Auditory Disorder",
        "acoustic neuroma": "Acoustic Neuroma",
        "eardrum": "Tympanic Membrane Disorder",
        "epistaxis": "Epistaxis / Nosebleed",
        "nosebleed": "Epistaxis / Nosebleed",
        # Women's Health / Gynaecology
        "endometriosis": "Endometriosis",
        "fibroid": "Uterine Fibroids",
        "uterine fibroid": "Uterine Fibroids",
        "menstrual": "Menstrual Disorder",
        "dysmenorrhea": "Dysmenorrhoea / Painful Periods",
        "amenorrhea": "Amenorrhoea / Absent Periods",
        "ovarian cyst": "Ovarian Cyst",
        "polycystic ovary": "PCOS / Polycystic Ovary Syndrome",
        "cervical": "Cervical Condition",
        "cervicitis": "Cervicitis / Cervical Infection",
        "vaginitis": "Vaginitis / Vaginal Infection",
        "vulvodynia": "Vulvodynia",
        "preeclampsia": "Preeclampsia / Pregnancy Complication",
        "ectopic pregnancy": "Ectopic Pregnancy",
        "menopause": "Menopause / Hormonal Change",
        "pelvic inflammatory": "Pelvic Inflammatory Disease",
        "pid": "Pelvic Inflammatory Disease",
        # Haematology
        "leukemia": "Leukaemia / Blood Cancer",
        "leukaemia": "Leukaemia / Blood Cancer",
        "lymphoma": "Lymphoma / Lymph Gland Cancer",
        "sickle cell": "Sickle Cell Anaemia",
        "thalassemia": "Thalassaemia / Blood Disorder",
        "thalassaemia": "Thalassaemia / Blood Disorder",
        "thrombocytopenia": "Thrombocytopenia / Low Platelets",
        "hemophilia": "Haemophilia / Bleeding Disorder",
        "haemophilia": "Haemophilia / Bleeding Disorder",
        "polycythemia": "Polycythaemia / High Blood Cell Count",
        "myeloma": "Multiple Myeloma",
        "aplastic anemia": "Aplastic Anaemia",
        "anemia": "Anaemia / Blood Deficiency",
        "anaemia": "Anaemia / Blood Deficiency",
        "iron deficiency": "Iron Deficiency Anaemia",
        # Oncology
        "cancer": "Oncological Condition (Cancer)",
        "tumour": "Oncological Condition (Tumour)",
        "tumor": "Oncological Condition (Tumour)",
        "breast cancer": "Breast Cancer",
        "lung cancer": "Lung Cancer",
        "colon cancer": "Colorectal Cancer",
        "colorectal": "Colorectal Cancer",
        "prostate cancer": "Prostate Cancer",
        "cervical cancer": "Cervical Cancer",
        "skin cancer": "Skin Cancer / Melanoma",
        "melanoma": "Melanoma / Skin Cancer",
        "stomach cancer": "Gastric Cancer",
        "liver cancer": "Hepatocellular Carcinoma",
        "pancreatic cancer": "Pancreatic Cancer",
        "ovarian cancer": "Ovarian Cancer",
        "thyroid cancer": "Thyroid Cancer",
        "brain tumor": "Brain Tumour",
        "brain tumour": "Brain Tumour",
        "lymph node": "Possible Lymphoma / Oncological Concern",
        "metastasis": "Metastatic Cancer",
        "carcinoma": "Carcinoma / Malignancy",
        # Infections / Tropical
        "malaria": "Malaria / Vector-Borne Fever",
        "dengue": "Dengue Fever",
        "typhoid": "Typhoid Fever",
        "covid": "COVID-19 / Viral Respiratory Infection",
        "coronavirus": "COVID-19 / Viral Respiratory Infection",
        "influenza": "Influenza / Seasonal Flu",
        "chickenpox": "Chickenpox / Varicella Infection",
        "varicella": "Chickenpox / Varicella Infection",
        "measles": "Measles / Viral Infection",
        "mumps": "Mumps / Viral Infection",
        "rubella": "Rubella / German Measles",
        "chikungunya": "Chikungunya / Viral Fever",
        "leptospirosis": "Leptospirosis",
        "typhus": "Typhus Fever",
        "rabies": "Rabies (Emergency — Seek Immediate Care)",
        "tetanus": "Tetanus / Lock Jaw",
        "hiv": "HIV / AIDS",
        "aids": "HIV / AIDS",
        "hepatitis a": "Hepatitis A",
        "hepatitis b": "Hepatitis B",
        "hepatitis c": "Hepatitis C",
        "hpv": "HPV / Human Papillomavirus",
        "herpes": "Herpes Simplex Infection",
        "cold sore": "Herpes Simplex / Cold Sore",
        "food poisoning": "Food Poisoning / Foodborne Illness",
        "sepsis": "Sepsis / Blood Infection (Emergency)",
    }

    for keyword, condition in disease_map.items():
        if keyword in combined:
            return condition

    # Garbage/Nonsense detection
    medical_keywords = [
        "pain", "ache", "fever", "cough", "head", "stomach", "breath", "blood", "skin",
        "hurt", "eye", "ear", "leg", "heart", "chest", "arm", "throat", "fatigue",
        "nausea", "vomit", "dizzy", "rash", "cold", "flu", "swelling", "burn", "weak"
    ]
    if len(s) < 3 or not any(kw in combined for kw in medical_keywords):
        return "Unclear Symptoms (Need more detail)"

    if "chest pain" in s or ("chest" in s and "pain" in s):
        return "Possible Cardiac Condition"
    if "severe headache" in s and ("blurred vision" in s or "vision" in s):
        return "Neurological Issue (High Risk)"
    if "thirst" in s and "urination" in s:
        return "Diabetes Related Symptoms"
    if "cough" in s and ("night sweats" in s or "fever" in s):
        return "Possible Respiratory Infection / TB"
    if "wheezing" in s or "breath" in s:
        return "Asthma / Respiratory Distress"

    if "stomach" in s or "abdomen" in s or "belly" in s:
        if "right" in s and "lower" in s:
            return "Potential Appendicitis"
        return "Gastrointestinal Issue / Gastritis"

    if "lung" in s or ("chest" in s and "pain" in s):
        return "Respiratory / Pulmonary Condition"
    if "fever" in s:
        return "Viral Infection / Fever"
    if "cough" in s:
        return "Common Cold / Bronchitis"
    if "cold" in s or "flu" in s:
        return "Influenza / Common Cold"
    if "headache" in s:
        return "Migraine / Tension Headache"
    if "skin" in s or "rash" in s:
        return "Dermatological Condition"
    if "throat" in s:
        return "Pharyngitis / Sore Throat"
    if "nausea" in s or "vomit" in s:
        return "Gastric Upset / Nausea"
    if "leg" in s or "arm" in s or "bone" in s or "joint" in s or "body pain" in s:
        return "Musculoskeletal Pain / Orthopedic Issue"
    if "fatigue" in s or "dizzy" in s:
        return "General Weakness / Fatigue"
    if "checkup" in s:
        return "General Wellness Checkup"

    if "heart" in h and not any(kw in s for kw in ["leg", "arm", "stomach", "head"]):
        return "Cardiac Related (Previous Mention)"

    return "General Clinical Assessment Needed"


# Helper for Specialization Mapping
def get_specialization_for_condition(condition):
    spec_map = {
        "Cardiac": "Cardiology", "Heart": "Cardiology", "Hypertension": "Cardiology",
        "Hypotension": "Cardiology", "Cholesterol": "Cardiology", "Arrhythmia": "Cardiology",
        "Angina": "Cardiology", "Pericarditis": "Cardiology", "Cardiomyopathy": "Cardiology",
        "Atrial Fibrillation": "Cardiology", "DVT": "Cardiology", "Deep Vein": "Cardiology",
        "Varicose": "Cardiology", "Aortic": "Cardiology", "Mitral": "Cardiology",
        "Peripheral Artery": "Cardiology",
        "Neurological": "Neurology", "Migraine": "Neurology", "Epilepsy": "Neurology",
        "Seizure": "Neurology", "Stroke": "Neurology", "Paralysis": "Neurology",
        "Parkinson": "Neurology", "Alzheimer": "Neurology", "Dementia": "Neurology",
        "Vertigo": "Neurology", "Meningitis": "Neurology", "Encephalitis": "Neurology",
        "Bell's Palsy": "Neurology", "Multiple Sclerosis": "Neurology",
        "Neuropathy": "Neurology", "Trigeminal": "Neurology", "Cerebral Palsy": "Neurology",
        "Guillain": "Neurology", "ALS": "Neurology", "Motor Neuron": "Neurology",
        "Hydrocephalus": "Neurology", "TIA": "Neurology", "Mini Stroke": "Neurology",
        "Meniere": "Neurology", "Acoustic Neuroma": "Neurology",
        "Diabetes": "Endocrinology", "Thyroid": "Endocrinology", "Obesity": "Endocrinology",
        "Metabolic": "Endocrinology", "PCOS": "Endocrinology", "Hormonal": "Endocrinology",
        "Goitre": "Endocrinology", "Adrenal": "Endocrinology", "Cushing": "Endocrinology",
        "Addison": "Endocrinology", "Hyperuricemia": "Endocrinology",
        "Respiratory": "Pulmonology", "Pulmonary": "Pulmonology", "Asthma": "Pulmonology",
        "Bronchitis": "Pulmonology", "Tuberculosis": "Pulmonology", "TB": "Pulmonology",
        "Pneumonia": "Pulmonology", "COPD": "Pulmonology", "Emphysema": "Pulmonology",
        "Pleuritis": "Pulmonology", "Pleurisy": "Pulmonology", "Sleep Apnoea": "Pulmonology",
        "Cystic Fibrosis": "Pulmonology", "Pertussis": "Pulmonology",
        "Pulmonary Embolism": "Pulmonology", "Pulmonary Fibrosis": "Pulmonology",
        "Sinusitis": "ENT", "Tonsillitis": "ENT", "Rhinitis": "ENT",
        "Laryngitis": "ENT", "Nasal Polyp": "ENT", "Deviated": "ENT",
        "Adenoid": "ENT", "Otitis": "ENT", "Tinnitus": "ENT",
        "Hearing Loss": "ENT", "Epistaxis": "ENT", "Hay Fever": "ENT",
        "Gastrointestinal": "Gastroenterology", "Gastritis": "Gastroenterology",
        "Gastro": "Gastroenterology", "Ulcer": "Gastroenterology", "Colitis": "Gastroenterology",
        "Bowel": "Gastroenterology", "Constipation": "Gastroenterology",
        "Diarrhoea": "Gastroenterology", "Crohn": "Gastroenterology",
        "Diverticulitis": "Gastroenterology", "Pancreatitis": "Gastroenterology",
        "GERD": "Gastroenterology", "Acid Reflux": "Gastroenterology",
        "Achalasia": "Gastroenterology", "Celiac": "Gastroenterology",
        "Coeliac": "Gastroenterology", "Lactose": "Gastroenterology",
        "Food Poisoning": "Gastroenterology",
        "Jaundice": "Hepatology", "Hepatitis": "Hepatology", "Liver": "Hepatology",
        "Cirrhosis": "Hepatology", "Fatty Liver": "Hepatology", "NAFLD": "Hepatology",
        "Hepatocellular": "Hepatology",
        "Hernia": "General Surgery", "Appendicitis": "General Surgery",
        "Piles": "General Surgery", "Haemorrhoids": "General Surgery",
        "Anal Fissure": "General Surgery", "Fistula": "General Surgery",
        "Gallstone": "General Surgery", "Cholecystitis": "General Surgery",
        "Gallbladder": "General Surgery",
        "Musculoskeletal": "Orthopedics", "Orthopedic": "Orthopedics",
        "Arthritis": "Orthopedics", "Fracture": "Orthopedics", "Gout": "Orthopedics",
        "Sciatica": "Orthopedics", "Spinal": "Orthopedics", "Disc": "Orthopedics",
        "Spondylitis": "Orthopedics", "Spondylosis": "Orthopedics",
        "Osteoporosis": "Orthopedics", "Bursitis": "Orthopedics",
        "Tendinitis": "Orthopedics", "Carpal Tunnel": "Orthopedics",
        "Rotator Cuff": "Orthopedics", "Plantar": "Orthopedics",
        "Fibromyalgia": "Orthopedics", "Myositis": "Orthopedics",
        "Frozen Shoulder": "Orthopedics", "Tennis Elbow": "Orthopedics",
        "ACL": "Orthopedics", "Meniscus": "Orthopedics", "Ligament": "Orthopedics",
        "Osteoarthritis": "Orthopedics",
        "Dermatological": "Dermatology", "Psoriasis": "Dermatology", "Eczema": "Dermatology",
        "Fungal": "Dermatology", "Allergic": "Dermatology", "Urticaria": "Dermatology",
        "Skin": "Dermatology", "Acne": "Dermatology", "Vitiligo": "Dermatology",
        "Shingles": "Dermatology", "Herpes Zoster": "Dermatology", "Melasma": "Dermatology",
        "Alopecia": "Dermatology", "Cellulitis": "Dermatology", "Impetigo": "Dermatology",
        "Rosacea": "Dermatology", "Scabies": "Dermatology", "Wart": "Dermatology",
        "Tinea": "Dermatology", "Ringworm": "Dermatology", "Contact Dermatitis": "Dermatology",
        "Kidney": "Nephrology", "Renal": "Nephrology", "Nephrotic": "Nephrology",
        "Nephritis": "Nephrology", "Glomerulo": "Nephrology", "Hydronephrosis": "Nephrology",
        "Chronic Kidney": "Nephrology",
        "Urinary Tract": "Urology", "UTI": "Urology", "Cystitis": "Urology",
        "Prostatitis": "Urology", "Prostate": "Urology", "BPH": "Urology",
        "Benign Prostatic": "Urology", "Incontinence": "Urology",
        "Overactive Bladder": "Urology",
        "Cataract": "Ophthalmology", "Glaucoma": "Ophthalmology",
        "Conjunctivitis": "Ophthalmology", "Eye": "Ophthalmology",
        "Retinal": "Ophthalmology", "Macular": "Ophthalmology",
        "Dry Eye": "Ophthalmology", "Uveitis": "Ophthalmology",
        "Strabismus": "Ophthalmology", "Keratoconus": "Ophthalmology",
        "Blepharitis": "Ophthalmology", "Amblyopia": "Ophthalmology",
        "Diabetic Retinopathy": "Ophthalmology",
        "Depression": "Psychiatry", "Anxiety": "Psychiatry", "Insomnia": "Psychiatry",
        "Mood": "Psychiatry", "OCD": "Psychiatry", "Obsessive": "Psychiatry",
        "PTSD": "Psychiatry", "Trauma": "Psychiatry", "Bipolar": "Psychiatry",
        "Schizophrenia": "Psychiatry", "Psychosis": "Psychiatry", "ADHD": "Psychiatry",
        "Panic": "Psychiatry", "Autism": "Psychiatry", "Phobia": "Psychiatry",
        "Anorexia": "Psychiatry", "Bulimia": "Psychiatry", "Burnout": "Psychiatry",
        "Oncological": "Oncology", "Cancer": "Oncology", "Tumour": "Oncology",
        "Melanoma": "Oncology", "Carcinoma": "Oncology", "Metastasis": "Oncology",
        "Leukaemia": "Oncology", "Lymphoma": "Haematology", "Myeloma": "Haematology",
        "Anaemia": "Haematology", "Anemia": "Haematology", "Blood Deficiency": "Haematology",
        "Sickle Cell": "Haematology", "Thalassaemia": "Haematology",
        "Thalassemia": "Haematology", "Thrombocytopenia": "Haematology",
        "Haemophilia": "Haematology", "Hemophilia": "Haematology",
        "Polycythaemia": "Haematology", "Aplastic": "Haematology",
        "Iron Deficiency": "Haematology",
        "Endometriosis": "Gynaecology", "Fibroid": "Gynaecology",
        "Menstrual": "Gynaecology", "Dysmenorrhoea": "Gynaecology",
        "Amenorrhoea": "Gynaecology", "Ovarian Cyst": "Gynaecology",
        "Cervical": "Gynaecology", "Vaginitis": "Gynaecology",
        "Vulvodynia": "Gynaecology", "Preeclampsia": "Gynaecology",
        "Ectopic": "Gynaecology", "Menopause": "Gynaecology",
        "Pelvic Inflammatory": "Gynaecology",
        "Viral": "General Medicine", "Influenza": "General Medicine", "Fever": "General Medicine",
        "Pharyngitis": "General Medicine", "Wellness": "General Medicine",
        "Malaria": "General Medicine", "Dengue": "General Medicine", "Typhoid": "General Medicine",
        "COVID": "General Medicine", "Fatigue": "General Medicine", "Weakness": "General Medicine",
        "Chickenpox": "General Medicine", "Measles": "General Medicine",
        "Mumps": "General Medicine", "Rubella": "General Medicine",
        "Chikungunya": "General Medicine", "Leptospirosis": "General Medicine",
        "Typhus": "General Medicine", "HIV": "General Medicine", "AIDS": "General Medicine",
        "Herpes Simplex": "General Medicine", "Sepsis": "General Medicine",
        "Tetanus": "General Medicine", "Rabies": "General Medicine",
        "General": "General Medicine"
    }
    for key, spec in spec_map.items():
        if key.lower() in condition.lower():
            return spec
    return "General Medicine"

# --- 5. Prediction API Endpoint ---
@app.route('/predict', methods=['POST'])
def predict():
    if not model or not vectorizer:
        return jsonify({"error": "Model not trained yet."}), 500

    data = request.json
    if not data:
        return jsonify({"error": "No JSON data provided"}), 400

    history = data.get("history", "")
    symptoms = data.get("symptoms", "")
    vitals = data.get("vitals", {})

    primary_condition = engine_predict_disease(symptoms)

    text_input = f"{history} {symptoms}"
    text_vec = vectorizer.transform([text_input])
    predicted_urgency = model.predict(text_vec)[0]

    probs = model.predict_proba(text_vec)[0]
    classes = model.classes_
    top_3 = [{"label": c, "probability": round(p * 100, 1)} for c, p in zip(classes, probs)]

    hr = float(vitals.get('heart_rate', 0) if vitals else 0)
    bp_sys = float(vitals.get('bp_systolic', 0) if vitals else 0)

    final_urgency = predicted_urgency
    if bp_sys > 170 or (hr > 120 and "Cardiac" in primary_condition):
        final_urgency = "Critical"
    elif bp_sys > 145 or hr > 110:
        if final_urgency == "Low": final_urgency = "Medium"

    recommended_spec = get_specialization_for_condition(primary_condition)

    return jsonify({
        "disease": primary_condition,
        "urgency": final_urgency,
        "ml_urgency": predicted_urgency,
        "probabilities": top_3,
        "specialization": recommended_spec
    })

# --- 6. Continuous Learning Endpoint ---
@app.route('/learn', methods=['POST'])
def learn():
    data = request.json
    required = ["history", "symptoms", "diagnosis"]
    if not all(k in data for k in required):
        return jsonify({"error": "Missing data"}), 400

    new_record = {
        "Medical_History": data['history'],
        "Symptoms": data['symptoms'],
        "Keyword_Symptoms": data.get('keywords', data['symptoms']),
        "AI_Urgency": data.get('urgency', 'Medium'),
        "Disease_Label": data['diagnosis']
    }

    try:
        new_df = pd.DataFrame([new_record])
        if not os.path.exists(FEEDBACK_PATH):
            new_df.to_csv(FEEDBACK_PATH, index=False)
        else:
            new_df.to_csv(FEEDBACK_PATH, mode='a', header=False, index=False)

        train_model()
        return jsonify({"status": "success", "message": "Knowledge updated."})
    except Exception as e:
        return jsonify({"error": str(e)}), 500

# --- 7. Medicine Alternative Suggestion ---
@app.route('/suggest_alternative', methods=['POST'])
def suggest_alternative():
    if not med_db:
        load_med_db()

    data = request.json
    if not data or 'medicine' not in data:
        return jsonify({"error": "No medicine provided"}), 400

    med_input = data['medicine'].lower().strip()

    target_med = None
    for m in med_db:
        if m['name'].lower() == med_input:
            target_med = m
            break
        if any(b.lower() == med_input for b in m.get('brand_names', [])):
            target_med = m
            break

    if not target_med:
        return jsonify({
            "suggested_alternative": f"Generic {med_input.capitalize()}",
            "reason": "Medicine profile not found in smart database. Consult pharmacist for generic composition.",
            "dosage": "As per medical advice"
        })

    substitutes = []
    target_ingredients = set([i.lower() for i in target_med['active_ingredients']])
    target_category = target_med.get('category', '').lower()

    for m in med_db:
        if m['name'].lower() == target_med['name'].lower():
            continue
        m_ingredients = set([i.lower() for i in m['active_ingredients']])
        if target_ingredients & m_ingredients:
            substitutes.append({
                "name": m['name'],
                "brand": m['brand_names'][0] if m['brand_names'] else m['name'],
                "reason": f"Same composition: {', '.join(m['active_ingredients'])}",
                "dosage": m['strength']
            })

    if not substitutes and target_category:
        for m in med_db:
            if m['name'].lower() == target_med['name'].lower(): continue
            if m.get('category', '').lower() == target_category:
                substitutes.append({
                    "name": m['name'],
                    "brand": m['brand_names'][0] if m['brand_names'] else m['name'],
                    "reason": f"Same therapeutic class: {m['category']}",
                    "dosage": m['strength']
                })

    if substitutes:
        top = substitutes[0]
        return jsonify({
            "original": target_med['name'],
            "suggested_alternative": top['brand'],
            "reason": top['reason'],
            "dosage": top['dosage']
        })

    return jsonify({
        "suggested_alternative": f"Generic {target_med['name']}",
        "reason": f"No direct substitute found in local records for {target_med.get('category', 'this class')}.",
        "dosage": target_med['strength']
    })

# --- 8. Interactive Symptom Checker ---
import requests as req_lib

@app.route('/symptom_chat', methods=['POST'])
def symptom_chat():
    data = request.json
    if not data or 'messages' not in data:
        return jsonify({"error": "No message history provided"}), 400

    messages = data['messages']

    gemini_api_key = os.environ.get("GEMINI_API_KEY", "")
    if gemini_api_key:
        try:
            import google.generativeai as genai
            genai.configure(api_key=gemini_api_key)
            gemini_model = genai.GenerativeModel('gemini-1.5-pro')
            system_prompt = """You are an advanced medical clinical extraction engine for a hospital strictly operating in a clinical context.
Your ONLY purpose is to assess medical symptoms, triage patients, and recommend the appropriate hospital department.
You MUST ONLY speak in professional, medical, or clinical hospital language.
If the user asks about ANYTHING non-medical, you MUST refuse and state: "I am a clinical AI assistant. I can only assist with medical symptom triage."
Reply strictly in JSON format: {"reply": "...", "diagnosis": "...", "specialization": "...", "urgency": "High/Medium/Low", "finished": true/false}"""

            prompt_msgs = system_prompt + "\n\nChat History:\n" + "\n".join([m['role'] + ": " + m['content'] for m in messages])
            response = gemini_model.generate_content(prompt_msgs, generation_config={"response_mime_type": "application/json"})
            ai_result = json.loads(response.text)
            return jsonify(ai_result)
        except Exception as e:
            print(f"Gemini API Connection Failed: {e}. Falling back to internal engine.")

    turn_count = len([m for m in messages if m['role'] == 'user'])
    all_symptoms = " ".join([m['content'] for m in messages if m['role'] == 'user']).lower()
    user_msgs = [m['content'] for m in messages if m['role'] == 'user']
    latest_user_msg = user_msgs[-1].lower() if user_msgs else ""

    medical_keywords = [
        "pain", "ache", "fever", "cough", "head", "stomach", "breath", "blood", "skin",
        "hurt", "eye", "ear", "leg", "heart", "sick", "ill", "doctor", "hospital",
        "medicine", "pill", "symptom", "disease", "vomit", "nausea", "dizzy", "fatigue",
        "weak", "cold", "flu", "rash", "itch", "swelling", "burn", "lung", "chest",
        "throat", "nose", "back", "arm", "joint", "bone", "muscle", "kidney", "liver",
        "urine", "stool", "bleed", "infection", "injury", "wound", "allerg", "cramp",
        "discharge", "numbness", "tremor", "palpitation", "breathless", "wheez",
        "angina", "arrhythmia", "hypertension", "hypotension", "cholesterol",
        "diabetes", "diabetic", "thyroid", "obesity", "pcos",
        "asthma", "tuberculosis", "pneumonia", "bronchitis", "copd",
        "gastritis", "appendicitis", "ulcer", "ibs", "colitis", "hepatitis", "jaundice",
        "migraine", "epilepsy", "seizure", "stroke", "paralysis", "vertigo", "dementia",
        "arthritis", "fracture", "sciatica", "osteoporosis",
        "psoriasis", "eczema", "fungal", "urticaria", "acne",
        "depression", "anxiety", "insomnia", "ocd", "ptsd", "bipolar", "adhd",
        "kidney stone", "uti", "renal", "cystitis", "prostate",
        "cataract", "glaucoma", "conjunctivitis",
        "cancer", "tumour", "tumor", "melanoma",
        "malaria", "dengue", "typhoid", "covid", "influenza", "sepsis",
    ]

    if not any(kw in all_symptoms for kw in medical_keywords) and len(all_symptoms.split()) > 0:
        return jsonify({
            "reply": "I am a clinical AI assistant. I can only assist with medical symptom triage and hospital department routing. Please describe your medical symptoms.",
            "finished": False
        })

    questions = []
    body_parts = [
        "chest", "stomach", "abdomen", "head", "back", "neck", "shoulder", "arm", "elbow",
        "wrist", "hand", "finger", "hip", "leg", "knee", "ankle", "foot", "toe",
        "throat", "ear", "eye", "nose", "lung", "heart", "liver", "kidney", "spine",
        "lower back", "upper back", "right side", "left side", "whole body", "joints"
    ]
    severity_words = [
        "mild", "moderate", "severe", "sharp", "dull", "constant", "intermittent",
        "unbearable", "slight", "intense", "terrible", "little", "lot", "bad",
        "worst", "tolerable", "1", "2", "3", "4", "5", "6", "7", "8", "9", "10"
    ]

    if any(w in all_symptoms for w in ["pain", "ache", "hurt", "swelling", "numb", "burn", "cramp", "discomfort"]):
        if not any(part in all_symptoms for part in body_parts):
            questions.append("Which part of your body is affected or hurting? (e.g., Chest, Stomach, Head, Back, Leg, Joints)")

    if not any(word in all_symptoms for word in ["day", "days", "week", "weeks", "month", "months", "hour", "hours", "since", "yesterday", "today", "ago", "morning", "night"]):
        questions.append("How long have you been experiencing these symptoms? (e.g. 2 days, 1 week, since yesterday)")

    if not any(word in all_symptoms for word in severity_words):
        questions.append("How severe are your symptoms on a scale of 1-10, or would you describe them as mild, moderate, or severe?")

    if "fever" in all_symptoms and not any(word in all_symptoms for word in ["degree", "high", "low", "100", "101", "102", "103", "104"]):
        questions.append("Do you know your approximate body temperature? Is it a high-grade fever (above 101°F)?")

    if questions:
        return jsonify({
            "reply": f"I see. {questions[0]}",
            "finished": False
        })

    try:
        disease = engine_predict_disease(latest_user_msg, all_symptoms)

        if "lung" in all_symptoms:
            disease = "Pulmonary / Respiratory Condition"
        elif "leg" in all_symptoms or "arm" in all_symptoms or "joint" in all_symptoms:
            disease = "Musculoskeletal / Orthopedic Issue"
        elif "stomach" in all_symptoms or "abdom" in all_symptoms:
            disease = "Gastrointestinal Condition"

        spec = get_specialization_for_condition(disease)
        text_vec = vectorizer.transform([all_symptoms])
        urgency = str(model.predict(text_vec)[0])

        return jsonify({
            "reply": f"Thank you for the detailed information. Based on your symptoms, our AI concludes a **{disease}**. The recommended department for this condition is **{spec}**. Would you like to proceed with booking an appointment?",
            "diagnosis": disease,
            "specialization": spec,
            "urgency": urgency,
            "finished": True
        })
    except Exception as e:
        import traceback
        traceback.print_exc()
        return jsonify({
            "reply": "I've noted your symptoms. Let's redirect you to a General Physician for a professional assessment.",
            "diagnosis": "Unspecified Symptoms",
            "specialization": "General Medicine",
            "urgency": "Medium",
            "finished": True
        })

# --- Blood Expiration ML Predictor ---
blood_model_ml = None

def train_blood_model():
    global blood_model_ml
    try:
        from sklearn.tree import DecisionTreeRegressor
        import numpy as np
        X = np.array([[1], [2], [3], [4]])
        y = np.array([42, 42, 5, 365])
        blood_model_ml = DecisionTreeRegressor()
        blood_model_ml.fit(X, y)
        print("Blood expiration model trained.")
    except Exception as e:
        print("Could not train blood model:", e)

@app.route('/predict_blood_expiry', methods=['POST'])
def predict_blood_expiry():
    data = request.json or {}
    collection_date_str = data.get('collection_date', '')
    component = data.get('component', 'Whole Blood')

    import datetime
    try:
        if collection_date_str:
            col_date = datetime.datetime.strptime(collection_date_str, "%Y-%m-%d")
        else:
            col_date = datetime.datetime.now()
    except:
        col_date = datetime.datetime.now()

    comp_map = {"Whole Blood": 1, "PRBC": 2, "Platelets": 3, "Plasma": 4}
    enc = comp_map.get(component, 1)

    if blood_model_ml:
        days = int(blood_model_ml.predict([[enc]])[0])
    else:
        days = 42

    exp_date = col_date + datetime.timedelta(days=days)

    return jsonify({
        "calculated_days": days,
        "expiry_date": exp_date.strftime("%Y-%m-%d")
    })

if __name__ == '__main__':
    load_med_db()
    train_model()
    train_blood_model()
    app.run(host='0.0.0.0', port=5001, debug=True, use_reloader=False)
