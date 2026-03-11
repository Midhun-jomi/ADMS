import traceback
import ai.app
from flask import jsonify, request

ai.app.app.testing = True
ai.app.train_model()

try:
    with ai.app.app.test_request_context(json={"messages": [{"role": "user", "content": "I have stomach pain"}, {"role": "assistant", "content": "How long?"}, {"role": "user", "content": "for 2 days"}]}):
        res = ai.app.symptom_chat()
        print(res.get_json())
except Exception as e:
    traceback.print_exc()
