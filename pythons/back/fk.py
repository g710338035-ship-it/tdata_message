# 新建 test_app.py
from flask import Flask, jsonify

# 全局创建app实例（确保无缩进）
app = Flask(__name__)

# 简单路由（测试是否能加载）
@app.route("/test", methods=["GET"])
async def test():  # 保留异步，模拟原接口
    return jsonify({"status": 1, "message": "测试成功"})

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=False)